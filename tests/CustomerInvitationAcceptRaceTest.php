<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTenant;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Jetstream\Events\CustomerInvitationAccepted;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * An invitation is consumed once, by one request, or not at all.
 *
 * Accepting is not one write. It resolves the invitee, joins or creates a
 * customer account, switches the invitee's current account, deletes the
 * invitation and announces the result — and every one of those was issued on
 * its own, outside any transaction and without taking the invitation as a
 * lock. Two requests carrying the same signed link both read a row that is
 * still there and both go on to act on it.
 *
 * The rule this states is: exactly one request consumes the invitation and
 * performs its account and membership changes; a request that loses observes
 * that the invitation is already consumed and leaves nothing behind. "Leaves
 * nothing behind" is the half that a check on the way out cannot provide —
 * the account is created before the invitation is deleted, so a request that
 * fails after the first step and has no transaction to unwind has already
 * created an account nobody asked for.
 *
 * Exercised with two real connections to one PostgreSQL database. sqlite
 * cannot take part — a second connection to ":memory:" is a different
 * database, and it has no row locks to observe — so these skip there rather
 * than pretending a sequential run proves anything.
 */
class CustomerInvitationAcceptRaceTest extends OrchestraTestCase
{
    /**
     * The name of the second, independent connection to the same database.
     */
    protected const OTHER = 'jetstream_competitor';

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(CustomerAccount::class, CustomerAccountPolicy::class);
        Jetstream::useUserModel(User::class);
        Jetstream::createCustomerAccountsUsing(CreateCustomerAccount::class);

        // A second connection to whatever database the suite is running
        // against, so two transactions can be held open at once.
        $app->config->set(
            'database.connections.'.static::OTHER,
            $app->config->get('database.connections.'.$app->config->get('database.default'))
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The lazy refresh migrates on first access; do it before a second
        // connection looks at the schema.
        DB::table('users')->count();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two requests accepting one invitation needs one database reachable from two connections.');
        }

        // The suite wraps each test in a transaction on the default
        // connection, which a second connection cannot see. These tests commit
        // their own fixtures and clean up after themselves instead.
        DB::rollBack();

        // And with it, the transactions manager the harness installs. That one
        // discounts one pending transaction per transacting connection, so
        // that after-commit callbacks still fire inside the wrapping
        // transaction a test never commits. These tests gave that wrapper up a
        // line ago and open real transactions of their own, so they need the
        // manager an application actually runs with — otherwise "after commit"
        // would be answered here by the harness rather than by the code.
        $this->app->instance('db.transactions', $transactions = new DatabaseTransactionsManager);

        DB::connection()->setTransactionManager($transactions);
        DB::connection(static::OTHER)->setTransactionManager($transactions);

        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('set lock_timeout = 0');

            $this->wipe();

            DB::purge(static::OTHER);

            DB::beginTransaction();
        }

        parent::tearDown();
    }

    protected function wipe(): void
    {
        DB::table('customer_invitations')->delete();
        DB::table('customer_account_user')->delete();
        DB::table('customer_accounts')->delete();
        DB::table('tenant_user')->delete();
        DB::table('roles')->delete();
        DB::table('tenants')->delete();
        DB::table('users')->delete();
    }

    protected function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * A tenant, its owner, and an invitee who has been asked to become a
     * customer of it in their own right.
     *
     * @return array{\App\Models\Tenant, \Laravel\Jetstream\Tests\Fixtures\User, string}
     */
    protected function invitation(): array
    {
        $owner = $this->createUser('owner@acme.test');
        $invitee = $this->createUser('jane@example.test');

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        DB::table('customer_invitations')->insert([
            'id' => $id = (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'customer_account_id' => null,
            'email' => $invitee->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $invitee, $id];
    }

    /**
     * The signed link the invitation mail carries.
     */
    protected function link(string $invitationId): string
    {
        return URL::signedRoute('customer-invitations.accept', ['invitation' => $invitationId]);
    }

    /**
     * Take the invitation row the way an accepting request now does, and hold
     * it for the rest of the caller's transaction.
     */
    protected function holdInvitation(string $invitationId): void
    {
        DB::connection(static::OTHER)->beginTransaction();

        DB::connection(static::OTHER)
            ->table('customer_invitations')
            ->where('id', $invitationId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * How many customer accounts exist across every tenant.
     */
    protected function accountCount(): int
    {
        return DB::table('customer_accounts')->count();
    }

    /**
     * Every statement the given callback causes, in order.
     *
     * @return list<string>
     */
    protected function queriesDuring(callable $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            $callback();
        } catch (QueryException) {
            // The collision is the subject; what it did on the way there is
            // what is being recorded.
        }

        return $queries;
    }

    public function test_a_request_that_loses_the_invitation_does_not_get_as_far_as_creating_one(): void
    {
        // The competing request holds the invitation and has not finished.
        // This one has to contend for it before it does anything else, not
        // after: the account is created before the invitation is consumed, so
        // a request that reads the invitation without taking it goes on to
        // make an account and only then discovers it has lost.
        //
        // A transaction alone would undo that account, which is why the state
        // afterwards cannot tell the two apart. What can is whether the insert
        // was ever issued.
        [, $invitee, $id] = $this->invitation();

        $this->holdInvitation($id);

        DB::statement("set lock_timeout = '500ms'");

        $queries = $this->queriesDuring(function () use ($invitee, $id): void {
            $this->withoutExceptionHandling()->actingAs($invitee)->get($this->link($id));
        });

        DB::statement('set lock_timeout = 0');

        DB::connection(static::OTHER)->rollBack();

        // Only statements that succeeded are recorded, so the read that
        // could not take the lock is not among them. What matters is what
        // comes after it: nothing should have.
        $this->assertEmpty(
            array_filter($queries, fn (string $sql): bool => str_contains($sql, 'insert into "customer_accounts"')),
            'The request that could not take the invitation created a customer account before finding out.'
        );

        $this->assertEmpty(
            array_filter($queries, fn (string $sql): bool => str_contains($sql, 'update "users"')),
            'The request that could not take the invitation switched the invitee to an account anyway.'
        );

        $this->assertSame(0, $this->accountCount());
        $this->assertSame(1, DB::table('customer_invitations')->count());
    }

    public function test_consuming_an_invitation_that_is_already_gone_is_silent(): void
    {
        // Why the lock is where it is, rather than a check on the way out.
        // Deleting a row another transaction has already deleted is not an
        // error on PostgreSQL — it simply affects nothing — so a request that
        // reads the invitation, acts on it, and finishes by deleting it has no
        // way of learning that someone else consumed it in between. Nothing
        // here goes through the controller: this is the engine behaviour the
        // fix is shaped around, and if it ever stopped being true the shape
        // would be worth revisiting.
        [, , $id] = $this->invitation();

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();
        $other->table('customer_invitations')->where('id', $id)->delete();
        $other->commit();

        $this->assertSame(0, DB::table('customer_invitations')->where('id', $id)->delete());
    }

    public function test_the_invitation_stays_held_for_the_whole_acceptance(): void
    {
        // Taking the invitation is not enough on its own; it has to stay taken
        // until the acceptance is finished. A locking read outside a
        // transaction holds nothing — PostgreSQL ends the statement and
        // releases it — so the window would simply move later.
        //
        // Checked from the middle of the acceptance: as the account is being
        // created, a competing request asks for the invitation and must be
        // refused. It asks with a short lock_timeout so that it gives up
        // rather than waiting for a transaction that cannot finish until it
        // returns.
        [, $invitee, $id] = $this->invitation();

        $refused = null;

        $competitor = function () use ($id, &$refused): void {
            $other = DB::connection(static::OTHER);

            $other->beginTransaction();

            try {
                $other->statement("set lock_timeout = '200ms'");

                $other->table('customer_invitations')->where('id', $id)->lockForUpdate()->get();

                $refused = false;
            } catch (QueryException $e) {
                $refused = str_contains($e->getMessage(), 'lock timeout');
            } finally {
                $other->rollBack();

                $other->statement('set lock_timeout = 0');
            }
        };

        // Disarmed after it fires rather than removed afterwards: Eloquent
        // boots a model class once per process, and flushEventListeners()
        // would take BelongsToTenant's tenant-stamping hook with it.
        CustomerAccount::creating(function () use (&$competitor): void {
            if ($competitor !== null) {
                $race = $competitor;

                $competitor = null;

                $race();
            }
        });

        $this->actingAs($invitee)->get($this->link($id));

        $this->assertTrue(
            $refused,
            'A competing request could take the invitation while it was being accepted.'
        );
    }

    public function test_only_one_of_two_requests_consumes_the_invitation(): void
    {
        // The competitor accepts in full and commits. The invitation is gone
        // before this request reads it, which is the ordinary outcome of the
        // race once one side wins.
        [$tenant, $invitee, $id] = $this->invitation();

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $other->table('customer_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'user_id' => $invitee->getKey(),
            'name' => $invitee->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $other->table('customer_invitations')->where('id', $id)->delete();

        $other->commit();

        Event::fake([CustomerInvitationAccepted::class]);

        $response = $this->actingAs($invitee)->get($this->link($id));

        $response->assertNotFound();

        $this->assertSame(1, $this->accountCount(), 'The losing request created a second customer account.');

        Event::assertNotDispatched(CustomerInvitationAccepted::class);
    }

    public function test_the_request_that_wins_performs_every_part_of_the_acceptance(): void
    {
        // The other half of the rule: serializing must not cost the winner
        // anything. One request on its own still joins, switches and announces.
        [$tenant, $invitee, $id] = $this->invitation();

        Event::fake([CustomerInvitationAccepted::class]);

        $response = $this->actingAs($invitee)->get($this->link($id));

        $response->assertRedirect(route('portal.show'));

        $this->assertSame(1, $this->accountCount());
        $this->assertSame(0, DB::table('customer_invitations')->count());

        $account = DB::table('customer_accounts')->first();

        $this->assertNotNull($account);
        $this->assertSame($tenant->getKey(), $account->tenant_id);
        $this->assertSame($invitee->getKey(), $account->user_id);

        $this->assertSame(
            $account->id,
            DB::table('users')->where('id', $invitee->getKey())->value('current_customer_account_id')
        );

        Event::assertDispatched(CustomerInvitationAccepted::class);
    }

    public function test_a_listener_can_see_the_acceptance_it_is_told_about(): void
    {
        // The event is raised inside the transaction but its listeners run
        // only after that transaction's connection commits at the outermost
        // level. Where it is raised decides which transaction carries it;
        // when the listeners run decides what they can see. A listener that
        // reads the account — a queue worker, another process, anything not
        // on this connection — would otherwise be handed an account it cannot
        // find. Checked from the second connection, which is exactly the
        // position such a listener is in.
        [, $invitee, $id] = $this->invitation();

        $visible = null;

        Event::listen(function (CustomerInvitationAccepted $event) use (&$visible): void {
            $visible = DB::connection(static::OTHER)
                ->table('customer_accounts')
                ->where('id', $event->account->getKey())
                ->exists();
        });

        $this->actingAs($invitee)->get($this->link($id));

        $this->assertTrue($visible, 'The acceptance was announced before anyone else could see it.');
    }

    public function test_an_invitation_into_an_existing_account_is_consumed_the_same_way(): void
    {
        // The other branch: joining an account that already exists rather than
        // creating one. The membership pivot has a unique index, so a repeated
        // attach would be refused rather than silently doubled — but the
        // invitation is what is being consumed either way, and it has to be
        // taken here too or the loser attaches first and fails afterwards.
        $owner = $this->createUser('owner@acme.test');
        $invitee = $this->createUser('jane@example.test');

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);
        $account = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'Acme Co']);

        DB::table('customer_invitations')->insert([
            'id' => $id = (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'customer_account_id' => $account->getKey(),
            'email' => $invitee->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->holdInvitation($id);

        DB::statement("set lock_timeout = '500ms'");

        $queries = $this->queriesDuring(function () use ($invitee, $id): void {
            $this->withoutExceptionHandling()->actingAs($invitee)->get($this->link($id));
        });

        DB::statement('set lock_timeout = 0');

        DB::connection(static::OTHER)->rollBack();

        $this->assertEmpty(
            array_filter($queries, fn (string $sql): bool => str_contains($sql, 'insert into "customer_account_user"')),
            'The request that could not take the invitation added the member before finding out.'
        );

        $this->assertSame(0, DB::table('customer_account_user')->count());

        // And with nobody holding it, the same invitation is accepted in full.
        $this->actingAs($invitee)->get($this->link($id))->assertRedirect(route('portal.show'));

        $this->assertSame(1, DB::table('customer_account_user')->count());
        $this->assertSame(0, DB::table('customer_invitations')->count());
        $this->assertSame(1, $this->accountCount());
    }

    /**
     * Record, for each announcement, whether the account was visible from the
     * second connection at the moment it was made.
     *
     * @param  list<bool>  $announced
     */
    protected function recordAnnouncements(array &$announced): void
    {
        Event::listen(function (CustomerInvitationAccepted $event) use (&$announced): void {
            $announced[] = DB::connection(static::OTHER)
                ->table('customer_accounts')
                ->where('id', $event->account->getKey())
                ->exists();
        });
    }

    public function test_the_acceptance_waits_for_the_outermost_transaction_to_commit(): void
    {
        // Accepting inside a transaction someone else opened — transaction
        // middleware, a larger workflow, a job that wraps its work. The
        // controller's own transaction is a savepoint there, and its commit
        // settles nothing: the account is still invisible to everyone else,
        // and the outer transaction could still take it away.
        [, $invitee, $id] = $this->invitation();

        $announced = [];

        $this->recordAnnouncements($announced);

        DB::beginTransaction();

        try {
            $this->actingAs($invitee)->get($this->link($id));

            $this->assertSame([], $announced, 'The acceptance was announced while an outer transaction was still open.');

            $this->assertFalse(
                DB::connection(static::OTHER)->table('customer_accounts')->exists(),
                'The account was visible elsewhere before the outermost transaction committed.'
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->assertSame(
            [true],
            $announced,
            'The acceptance was not announced once the outermost transaction committed, or was announced too early to see.'
        );
    }

    public function test_an_acceptance_undone_by_the_outermost_transaction_is_never_announced(): void
    {
        // The other half, and the worse one: the outer transaction rolls back
        // and the acceptance never happened. Anything already told about it
        // would be acting on an account, a membership and a switched context
        // that no longer exist.
        [, $invitee, $id] = $this->invitation();

        $announced = [];

        $this->recordAnnouncements($announced);

        DB::beginTransaction();

        $this->actingAs($invitee)->get($this->link($id));

        DB::rollBack();

        $this->assertSame([], $announced, 'An acceptance that was rolled back was announced anyway.');

        $this->assertSame(0, $this->accountCount());
        $this->assertSame(1, DB::table('customer_invitations')->count(), 'The invitation was consumed by a transaction that rolled back.');
    }

    public function test_an_unrelated_connection_committing_does_not_announce_the_acceptance(): void
    {
        // The manager that decides when an after-commit event fires keeps one
        // pending list for every connection at once and attaches the callback
        // to whichever transaction was begun most recently — it is never told
        // which connection the event belongs to. So the acceptance has to be
        // announced from inside its own transaction, while that is still the
        // newest pending one. Announced afterwards, the newest is whatever
        // else happens to be open, and somebody else's commit sets it off.
        [, $invitee, $id] = $this->invitation();

        $announced = [];

        $this->recordAnnouncements($announced);

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        try {
            $this->actingAs($invitee)->get($this->link($id));

            $this->assertSame([], $announced, 'The acceptance was announced while its own transaction was still open.');

            // An unrelated connection finishing has nothing to do with whether
            // this acceptance is durable.
            $other->commit();

            $this->assertSame(
                [],
                $announced,
                'The acceptance was announced when an unrelated connection committed.'
            );

            $this->assertFalse(
                $other->table('customer_accounts')->exists(),
                'The account was visible elsewhere before the invitation connection committed.'
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            throw $e;
        }

        $this->assertSame([true], $announced, 'The acceptance was not announced once its own connection committed.');
    }

    public function test_an_unrelated_commit_cannot_announce_an_acceptance_that_is_then_undone(): void
    {
        // The same ordering, ending the way it must never be allowed to end:
        // the unrelated connection commits, the invitation connection rolls
        // back, and the acceptance never happened. Nothing may have been told
        // otherwise in between.
        [, $invitee, $id] = $this->invitation();

        $announced = [];

        $this->recordAnnouncements($announced);

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        $this->actingAs($invitee)->get($this->link($id));

        $other->commit();

        DB::rollBack();

        $this->assertSame([], $announced, 'An acceptance that was rolled back was announced by an unrelated commit.');

        $this->assertSame(0, $this->accountCount());
        $this->assertSame(1, DB::table('customer_invitations')->count());
    }

    public function test_the_acceptance_is_not_announced_when_it_does_not_happen(): void
    {
        // The event says an invitation was accepted. Nothing may hear that
        // until the acceptance is committed, or a listener acts on an account
        // that the failure has since taken away.
        [, $invitee, $id] = $this->invitation();

        $this->holdInvitation($id);

        DB::statement("set lock_timeout = '500ms'");

        Event::fake([CustomerInvitationAccepted::class]);

        try {
            $this->withoutExceptionHandling()->actingAs($invitee)->get($this->link($id));
        } catch (QueryException) {
            // The collision is the subject of the test above.
        } finally {
            DB::statement('set lock_timeout = 0');

            DB::connection(static::OTHER)->rollBack();
        }

        Event::assertNotDispatched(CustomerInvitationAccepted::class);
    }
}

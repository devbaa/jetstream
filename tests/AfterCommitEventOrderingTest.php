<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DomainClaim;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Actions\BlockUser;
use Laravel\Jetstream\Actions\VerifyDomainClaim;
use Laravel\Jetstream\Events\DomainClaimSuperseded;
use Laravel\Jetstream\Events\DomainClaimVerified;
use Laravel\Jetstream\Events\UserBlocked;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * An action that wraps its work in a transaction announces it once, afterwards.
 *
 * Three events were raised after their action's DB::transaction() had already
 * returned: UserBlocked, DomainClaimVerified and DomainClaimSuperseded. Where
 * the action opened the outermost transaction that reads as "after the
 * commit", and it is only there that it does.
 *
 * Laravel transactions nest. Inside one an application already had open —
 * transaction middleware, a larger workflow, a job that wraps its work — the
 * action's own transaction is a savepoint, and its commit settles nothing: the
 * change is still invisible to every other connection and can still be taken
 * away. Worse, the transaction manager that would hold a deferred event keeps
 * one pending list across every connection and attaches the callback to
 * whichever transaction was begun most recently, never being told which
 * connection the event is about. By the time the action's own transaction has
 * returned it is no longer pending, so the newest is whatever else happens to
 * be open — and an unrelated connection's commit announces a change that is
 * not durable, on a connection that may still roll it back.
 *
 * Exercised with two real connections to one PostgreSQL database. sqlite
 * cannot take part — a second connection to ":memory:" is a different database
 * — so these skip there rather than pretending a sequential run proves
 * anything.
 */
class AfterCommitEventOrderingTest extends OrchestraTestCase
{
    /**
     * The name of the second, independent connection to the same database.
     */
    protected const OTHER = 'jetstream_competitor';

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTeamEnvironment($app);

        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::domainAdmin();

        $app->config->set('jetstream.features', $features);

        Jetstream::useUserModel(User::class);

        $app->config->set(
            'database.connections.'.static::OTHER,
            $app->config->get('database.connections.'.$app->config->get('database.default'))
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->count();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Ordering between connections needs one database reachable from two of them.');
        }

        DB::rollBack();

        // With the harness's wrapping transaction goes the transactions manager
        // it installs, which discounts one pending transaction per transacting
        // connection so that after-commit callbacks still fire inside a
        // transaction the test never commits. These tests open real
        // transactions of their own and need the manager an application runs
        // with, or "after commit" would be answered here rather than by the
        // code.
        $this->app->instance('db.transactions', $transactions = new DatabaseTransactionsManager);

        DB::connection()->setTransactionManager($transactions);
        DB::connection(static::OTHER)->setTransactionManager($transactions);

        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->wipe();

            DB::purge(static::OTHER);

            DB::beginTransaction();
        }

        parent::tearDown();
    }

    protected function wipe(): void
    {
        // These tests commit their own fixtures rather than leaning on the
        // transaction the suite rolls back, so everything the actions under
        // test write has to be cleared by hand — including the audit entries
        // that blocking a user and verifying a claim record, which would
        // otherwise be waiting for whichever test runs next.
        DB::table('audit_logs')->delete();
        DB::table('domain_activities')->delete();
        DB::table('domain_claims')->delete();
        DB::table('team_user')->delete();
        DB::table('teams')->delete();
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
     * An unverified claim on the given domain.
     */
    protected function claimFor(User $user, string $domain = 'acme.com'): DomainClaim
    {
        return DomainClaim::forceCreate([
            'user_id' => $user->id,
            'domain' => $domain,
            'token' => 'token-'.$user->email.'-'.$domain,
        ]);
    }

    /**
     * Whether the given column of the given row is set, as the second
     * connection sees it — which is the position any other process is in.
     */
    protected function visibleElsewhere(string $table, string $id, string $column): bool
    {
        return DB::connection(static::OTHER)
            ->table($table)
            ->where('id', $id)
            ->whereNotNull($column)
            ->exists();
    }

    public function test_a_block_is_not_announced_by_an_unrelated_connection(): void
    {
        $user = $this->createUser('blocked@example.test');

        $announced = [];

        Event::listen(function (UserBlocked $event) use (&$announced): void {
            $announced[] = $this->visibleElsewhere('users', $event->user->getKey(), 'blocked_at');
        });

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        try {
            (new BlockUser)->block($user, 'spam');

            $this->assertSame([], $announced, 'The block was announced while its own transaction was still open.');

            $other->commit();

            $this->assertSame([], $announced, 'The block was announced when an unrelated connection committed.');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            throw $e;
        }

        $this->assertSame([true], $announced, 'The block was not announced once its own connection committed.');
    }

    public function test_a_block_undone_by_the_outermost_transaction_is_never_announced(): void
    {
        $user = $this->createUser('blocked@example.test');

        $announced = [];

        Event::listen(function (UserBlocked $event) use (&$announced): void {
            $announced[] = $this->visibleElsewhere('users', $event->user->getKey(), 'blocked_at');
        });

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        (new BlockUser)->block($user, 'spam');

        $other->commit();

        DB::rollBack();

        $this->assertSame([], $announced, 'A block that was rolled back was announced anyway.');

        $this->assertFalse(
            DB::table('users')->where('id', $user->getKey())->whereNotNull('blocked_at')->exists(),
            'The rolled back block was kept.'
        );
    }

    public function test_a_verification_is_not_announced_by_an_unrelated_connection(): void
    {
        $claim = $this->claimFor($this->createUser('taylor@acme.com'));

        $announced = [];

        Event::listen(function (DomainClaimVerified $event) use (&$announced): void {
            $announced[] = $this->visibleElsewhere('domain_claims', $event->claim->getKey(), 'verified_at');
        });

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        try {
            app(VerifyDomainClaim::class)->activate($claim, 'dns');

            $this->assertSame([], $announced, 'The verification was announced while its own transaction was still open.');

            $other->commit();

            $this->assertSame([], $announced, 'The verification was announced when an unrelated connection committed.');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            throw $e;
        }

        $this->assertSame([true], $announced, 'The verification was not announced once its own connection committed.');
    }

    public function test_a_verification_undone_by_the_outermost_transaction_is_never_announced(): void
    {
        $claim = $this->claimFor($this->createUser('taylor@acme.com'));

        $announced = [];

        Event::listen(function (DomainClaimVerified $event) use (&$announced): void {
            $announced[] = $this->visibleElsewhere('domain_claims', $event->claim->getKey(), 'verified_at');
        });

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        app(VerifyDomainClaim::class)->activate($claim, 'dns');

        $other->commit();

        DB::rollBack();

        $this->assertSame([], $announced, 'A verification that was rolled back was announced anyway.');

        $this->assertFalse(
            DB::table('domain_claims')->where('id', $claim->getKey())->whereNotNull('verified_at')->exists(),
            'The rolled back verification was kept.'
        );
    }

    public function test_a_supersession_is_not_announced_by_an_unrelated_connection(): void
    {
        // The third event, and the one that comes in a set: taking the flag
        // supersedes every other claim that held it.
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        app(VerifyDomainClaim::class)->activate($first, 'dns');

        $announced = [];

        Event::listen(function (DomainClaimSuperseded $event) use (&$announced): void {
            $announced[] = $this->visibleElsewhere('domain_claims', $event->claim->getKey(), 'superseded_at');
        });

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        try {
            app(VerifyDomainClaim::class)->activate($second, 'dns');

            $this->assertSame([], $announced, 'The supersession was announced while its own transaction was still open.');

            $other->commit();

            $this->assertSame([], $announced, 'The supersession was announced when an unrelated connection committed.');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            throw $e;
        }

        $this->assertSame([true], $announced, 'The supersession was not announced once its own connection committed.');
    }

    public function test_each_of_the_three_still_arrives_when_nothing_else_is_open(): void
    {
        // The ordinary case, which deferring must not change: with no
        // transaction around them these are announced as they always were.
        $user = $this->createUser('blocked@example.test');

        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        app(VerifyDomainClaim::class)->activate($first, 'dns');

        Event::fake([UserBlocked::class, DomainClaimVerified::class, DomainClaimSuperseded::class]);

        (new BlockUser)->block($user, 'spam');

        app(VerifyDomainClaim::class)->activate($second, 'dns');

        Event::assertDispatched(UserBlocked::class);
        Event::assertDispatched(DomainClaimVerified::class);
        Event::assertDispatched(DomainClaimSuperseded::class);
    }
}

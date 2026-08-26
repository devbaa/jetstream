<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Events\TenantStaffUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tests\Fixtures\TeamPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * The membership is held while its role is changed, and announced only once.
 *
 * Looking the membership up before writing to it is a check followed by a
 * write, and on its own that only narrows the window rather than closing it:
 * a second administrator revoking the membership in between leaves the UPDATE
 * matching nothing and the change announced anyway — the very thing the check
 * was added to prevent. So the row is taken with lockForUpdate() and the write
 * happens in the same transaction.
 *
 * That transaction then decides when the announcement may be believed, which
 * is the second thing these cover: the event is raised from inside it, and
 * carried by it, so no other connection's commit can announce a role change
 * that is not durable.
 *
 * Exercised with two real connections to one PostgreSQL database. sqlite
 * cannot take part — a second connection to ":memory:" is a different database
 * and it has no row locks to observe — so these skip there rather than
 * pretending a sequential run proves anything.
 */
class RoleUpdateRaceTest extends OrchestraTestCase
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
        Gate::policy(Team::class, TeamPolicy::class);

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
            $this->markTestSkipped('Holding a membership while it changes needs one database reachable from two connections.');
        }

        DB::rollBack();

        // With the harness's wrapping transaction goes the transactions
        // manager it installs, which discounts one pending transaction per
        // transacting connection so that after-commit callbacks still fire
        // inside a transaction the test never commits. These tests open real
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
            DB::statement('set lock_timeout = 0');

            $this->wipe();

            DB::purge(static::OTHER);

            DB::beginTransaction();
        }

        parent::tearDown();
    }

    protected function wipe(): void
    {
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
        ]);
    }

    /**
     * A tenant, its owner, and a staff member holding the "admin" role.
     *
     * @return array{\Laravel\Jetstream\Tests\Fixtures\User, \App\Models\Tenant, \Laravel\Jetstream\Tests\Fixtures\User}
     */
    protected function staffMembership(): array
    {
        $owner = $this->createUser('owner@acme.test');
        $staff = $this->createUser('staff@acme.test');

        $tenant = Tenant::forceCreate(['name' => 'Acme', 'slug' => 'acme', 'user_id' => $owner->id]);

        foreach (['admin', 'editor'] as $key) {
            Role::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'key' => $key,
                'name' => ucfirst($key),
                'permissions' => ['read'],
            ]);
        }

        app(RoleRegistry::class)->flush();

        $tenant->users()->attach($owner, ['role' => 'admin']);
        $tenant->users()->attach($staff, ['role' => 'admin']);

        return [$owner, $tenant, $staff];
    }

    /**
     * The role recorded against the tenant for the given user, if any.
     */
    protected function roleOf(Tenant $tenant, User $user, ?string $connection = null): ?string
    {
        $role = DB::connection($connection)
            ->table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        return is_string($role) ? $role : null;
    }

    /**
     * Record, for each announcement, the role visible from the second
     * connection at the moment it was made.
     *
     * @param  list<string|null>  $announced
     */
    protected function recordAnnouncements(Tenant $tenant, User $staff, array &$announced): void
    {
        Event::listen(function (TenantStaffUpdated $event) use ($tenant, $staff, &$announced): void {
            $announced[] = $this->roleOf($tenant, $staff, static::OTHER);
        });
    }

    public function test_the_membership_is_held_while_its_role_is_changed(): void
    {
        // The window a lookup on its own leaves open: between reading the
        // membership and writing to it, somebody else revokes it. Checked from
        // inside that window — the competing revocation is attempted the
        // moment the locking read returns, with a short lock_timeout so it
        // gives up rather than waiting for a transaction it is blocking.
        [$owner, $tenant, $staff] = $this->staffMembership();

        $refused = null;

        $competitor = function () use ($tenant, $staff, &$refused): void {
            $other = DB::connection(static::OTHER);

            $other->beginTransaction();

            try {
                $other->statement("set lock_timeout = '200ms'");

                $other->table('tenant_user')
                    ->where('tenant_id', $tenant->getKey())
                    ->where('user_id', $staff->getKey())
                    ->delete();

                $refused = false;
            } catch (QueryException $e) {
                $refused = str_contains($e->getMessage(), 'lock timeout');
            } finally {
                $other->rollBack();

                $other->statement('set lock_timeout = 0');
            }
        };

        DB::listen(function (QueryExecuted $query) use (&$competitor): void {
            if ($competitor !== null && str_contains($query->sql, 'for update')) {
                $race = $competitor;

                $competitor = null;

                $race();
            }
        });

        (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

        $this->assertTrue(
            $refused,
            'The membership was not held: it could be revoked while its role was being changed.'
        );

        $this->assertSame('editor', $this->roleOf($tenant, $staff));
    }

    public function test_a_membership_revoked_first_is_not_updated_or_announced(): void
    {
        // The same collision resolved the other way round: the revocation gets
        // there first and commits. Nothing to update, and nothing to say.
        [$owner, $tenant, $staff] = $this->staffMembership();

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $other->table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $staff->getKey())
            ->delete();

        $other->commit();

        Event::fake([TenantStaffUpdated::class]);

        try {
            (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

            $this->fail('A revoked membership was updated.');
        } catch (ModelNotFoundException) {
            //
        }

        Event::assertNotDispatched(TenantStaffUpdated::class);

        $this->assertNull($this->roleOf($tenant, $staff));
    }

    public function test_an_unrelated_connection_committing_does_not_announce_the_change(): void
    {
        // The manager holding a deferred event keeps one pending list for
        // every connection and attaches the callback to whichever transaction
        // was begun most recently, never being told which connection the event
        // belongs to. Raised from inside its own transaction, the change is
        // carried by that one; raised after it returned, it would be carried
        // by whatever else happened to be open.
        [$owner, $tenant, $staff] = $this->staffMembership();

        $announced = [];

        $this->recordAnnouncements($tenant, $staff, $announced);

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        try {
            (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

            $this->assertSame([], $announced, 'The change was announced while its own transaction was still open.');

            $other->commit();

            $this->assertSame([], $announced, 'The change was announced when an unrelated connection committed.');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            throw $e;
        }

        $this->assertSame(
            ['editor'],
            $announced,
            'The change was not announced once its own connection committed, or was announced too early to be seen.'
        );
    }

    public function test_a_change_undone_by_the_outermost_transaction_is_never_announced(): void
    {
        [$owner, $tenant, $staff] = $this->staffMembership();

        $announced = [];

        $this->recordAnnouncements($tenant, $staff, $announced);

        $other = DB::connection(static::OTHER);

        DB::beginTransaction();
        $other->beginTransaction();

        (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

        $other->commit();

        DB::rollBack();

        $this->assertSame([], $announced, 'A role change that was rolled back was announced anyway.');

        $this->assertSame('admin', $this->roleOf($tenant, $staff), 'The rolled back change was kept.');
    }
}

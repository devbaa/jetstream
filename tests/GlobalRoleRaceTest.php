<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Models\Tenant;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * Two nodes seeding the application's default roles produce one set of roles.
 *
 * The seeder looks for a default role before it writes one, which decides
 * nothing when two processes read before either writes: both see none, and both
 * insert. That is not a contrived interleaving — a rolling deploy that runs
 * `migrate --seed` on every node is enough, and so is a deploy step racing an
 * operator running it by hand.
 *
 * The unique index on (tenant_id, key) cannot stop them, because these rows are
 * exactly the ones whose tenant_id is NULL, and NULL is distinct from NULL in a
 * unique index here.
 *
 * The interleaving is exercised with two real connections to one PostgreSQL
 * database. sqlite cannot take part — a second connection to ":memory:" is a
 * different database — so these tests skip there rather than pretending a
 * sequential run proves anything.
 */
class GlobalRoleRaceTest extends OrchestraTestCase
{
    /**
     * The name of the second, independent connection to the same database.
     */
    protected const OTHER = 'jetstream_competitor';

    /**
     * The statically registered roles, restored between tests.
     *
     * @var array<string, \Laravel\Jetstream\Role>
     */
    protected array $registeredRoles = [];

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Jetstream::useUserModel(User::class);

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

        $this->registeredRoles = Jetstream::$roles;

        // The lazy refresh migrates on first access; do it before a second
        // connection looks at the schema.
        DB::table('users')->count();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A concurrent seed needs one database reachable from two connections.');
        }

        // The suite wraps each test in a transaction on the default
        // connection, which a second connection cannot see. These tests commit
        // their own fixtures and clean up after themselves instead.
        DB::rollBack();

        $this->wipe();
    }

    protected function tearDown(): void
    {
        Jetstream::$roles = $this->registeredRoles;

        if (DB::connection()->getDriverName() === 'pgsql') {
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

    /**
     * Insert a role row the way a seeder would, on a given connection.
     *
     * @param  list<string>  $permissions
     */
    protected function insertRole(string $connection, ?string $tenantId, string $key, array $permissions): void
    {
        DB::connection($connection)->table('roles')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => 'Role '.$key,
            'permissions' => json_encode($permissions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * How many application default roles carry this key.
     */
    protected function defaultCount(string $key): int
    {
        return DB::table('roles')->whereNull('tenant_id')->where('key', $key)->count();
    }

    public function test_two_simultaneous_default_role_seeds_cannot_both_persist(): void
    {
        $other = DB::connection(static::OTHER);

        // Both processes look for the default role before either has written
        // one. This is the whole of the seeder's check, and it is true for both
        // of them at this instant.
        $this->assertSame(0, $this->defaultCount('support'));

        $other->beginTransaction();

        $this->assertSame(
            0,
            $other->table('roles')->whereNull('tenant_id')->where('key', 'support')->count()
        );

        // The first process inserts and commits in full.
        $this->insertRole(static::OTHER, null, 'support', ['read']);

        $other->commit();

        // The second now finishes on the state it read.
        $failed = null;

        try {
            $this->insertRole(DB::getDefaultConnection(), null, 'support', ['read', 'delete']);
        } catch (QueryException $e) {
            $failed = $e;
        }

        $this->assertNotNull($failed, 'The database accepted a second application default role under the same key.');

        $this->assertSame(
            1,
            $this->defaultCount('support'),
            'Two default rows leave the permissions of the support role undetermined.'
        );
    }

    public function test_a_default_role_insert_waits_for_a_competing_one(): void
    {
        // Contention, not just rejection: while the first process's transaction
        // is open, the second must wait on the index rather than read "none
        // exists" and carry on to its own insert.
        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $this->insertRole(static::OTHER, null, 'support', ['read']);

        DB::statement("set lock_timeout = '500ms'");

        $waited = false;

        try {
            $this->insertRole(DB::getDefaultConnection(), null, 'support', ['read', 'delete']);
        } catch (QueryException $e) {
            $waited = str_contains($e->getMessage(), 'lock timeout');
        } finally {
            DB::statement('set lock_timeout = 0');

            $other->rollBack();
        }

        $this->assertTrue($waited, 'The second default role did not wait for the uncommitted first one.');
    }

    public function test_a_tenant_keeps_its_own_key_while_a_default_of_that_name_is_being_written(): void
    {
        // The other half, and what a constraint built by merging the two scopes
        // would get wrong: a tenant role and an application default sharing a
        // key are two different roles, so neither may block or reject the other.
        $owner = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner@acme.test',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $this->insertRole(static::OTHER, null, 'support', ['read']);

        DB::statement("set lock_timeout = '500ms'");

        try {
            $this->insertRole(DB::getDefaultConnection(), $tenant->getKey(), 'support', ['read', 'delete']);
        } finally {
            DB::statement('set lock_timeout = 0');

            $other->rollBack();
        }

        $this->assertSame(
            1,
            DB::table('roles')->where('tenant_id', $tenant->getKey())->where('key', 'support')->count(),
            'A tenant could not take a key while an application default of that name was being written.'
        );
    }

    public function test_the_seeder_reports_a_concurrent_seed_rather_than_duplicating(): void
    {
        // What the seeder does when it loses. Failing loudly is the intended
        // outcome: the run that lost has nothing left to do, because the run
        // that won wrote exactly the row it was going to write. Silently
        // producing a second row is what this replaces.
        Jetstream::$roles = [];

        Jetstream::role('support', 'Support', ['read']);

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $this->insertRole(static::OTHER, null, 'support', ['read']);

        $other->commit();

        // The seeder read before that landed; this stands for the insert it
        // then goes on to attempt.
        $failed = null;

        try {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'key' => 'support',
                'name' => 'Support',
                'permissions' => json_encode(['read']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $failed = $e;
        }

        $this->assertNotNull($failed);

        // And a seeder run after the dust settles is a no-op, so recovering
        // from the failure is running it again.
        (new DefaultRolesSeeder)->run();

        $this->assertSame(1, $this->defaultCount('support'));
    }
}

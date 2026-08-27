<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Tenant;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * A role key names one role per scope, and "no tenant" is a scope.
 *
 * The roles table is unique on (tenant_id, key). The application's default
 * roles are the rows where tenant_id is NULL — the base set every tenant
 * inherits and may override — and NULL is distinct from NULL in a unique index
 * on PostgreSQL, MySQL and sqlite. So the one index on the table does not
 * constrain the one group of rows that is shared by every tenant in the
 * installation.
 *
 * What that costs is not a duplicate row in a listing. RoleRegistry folds the
 * database roles into an array keyed by role key, so two defaults sharing a key
 * collapse into one entry and the survivor is whichever row came back last.
 * Nothing in the query breaks that tie: it orders by whether tenant_id is null,
 * a question both rows answer identically. The permissions a role grants
 * therefore stop being a property of the role.
 */
class GlobalRoleUniquenessTest extends OrchestraTestCase
{
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registeredRoles = Jetstream::$roles;
    }

    protected function tearDown(): void
    {
        Jetstream::$roles = $this->registeredRoles;

        parent::tearDown();
    }

    /**
     * Insert a role row directly, so that nothing but the database decides.
     *
     * @param  list<string>  $permissions
     */
    protected function insertRole(?string $tenantId, string $key, array $permissions): string
    {
        DB::table('roles')->insert([
            'id' => $id = (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => 'Role '.$key,
            'permissions' => json_encode($permissions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function createTenant(string $slug = 'acme'): Tenant
    {
        $owner = User::firstOrCreate(
            ['email' => 'taylor@laravel.com'],
            ['name' => 'Taylor Otwell', 'password' => 'secret']
        );

        return Tenant::forceCreate(['name' => 'Acme', 'slug' => $slug, 'user_id' => $owner->id]);
    }

    public function test_two_application_default_roles_cannot_share_a_key(): void
    {
        $this->insertRole(null, 'support', ['read']);

        $failed = null;

        try {
            $this->insertRole(null, 'support', ['read', 'delete']);
        } catch (QueryException $e) {
            $failed = $e;
        }

        $this->assertNotNull(
            $failed,
            'The database accepted a second application default role under a key already taken.'
        );

        $this->assertSame(
            1,
            DB::table('roles')->where('key', 'support')->whereNull('tenant_id')->count()
        );
    }

    public function test_a_tenant_may_still_take_a_key_an_application_default_holds(): void
    {
        // The whole point of a default: a tenant overrides it under the same
        // key. Making "no tenant" a scope of its own must not merge the two.
        $tenant = $this->createTenant();

        $this->insertRole(null, 'support', ['read']);
        $this->insertRole($tenant->id, 'support', ['read', 'delete']);

        $this->assertSame(2, DB::table('roles')->where('key', 'support')->count());

        app(RoleRegistry::class)->flush();

        $this->assertSame(
            ['read', 'delete'],
            Jetstream::findRole('support', $tenant)->permissions,
            'The tenant override no longer beats the application default.'
        );
    }

    public function test_two_tenants_may_still_each_hold_the_same_key(): void
    {
        $first = $this->createTenant('acme');
        $second = $this->createTenant('other');

        $this->insertRole($first->id, 'support', ['read']);
        $this->insertRole($second->id, 'support', ['read', 'delete']);

        $this->assertSame(2, DB::table('roles')->where('key', 'support')->count());
    }

    public function test_seeding_the_application_defaults_twice_changes_nothing(): void
    {
        // The control, and the reason the seeder is not where this is fixed:
        // its updateOrCreate is already idempotent when nothing runs beside it.
        // What a check followed by an insert cannot do is decide anything when
        // something does.
        Jetstream::role('support', 'Support', ['read']);

        (new DefaultRolesSeeder)->run();
        (new DefaultRolesSeeder)->run();

        $this->assertSame(
            1,
            DB::table('roles')->where('key', 'support')->whereNull('tenant_id')->count()
        );

        $this->assertSame(
            count(Jetstream::$roles),
            DB::table('roles')->whereNull('tenant_id')->count()
        );
    }
}

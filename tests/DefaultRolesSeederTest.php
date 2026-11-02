<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Role;
use App\Models\Tenant;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * The seeder that copies the application's role catalog into the roles table.
 *
 * Its rows are the application defaults — tenant_id NULL — and it is the only
 * writer of them this package ships. It had never been exercised: the seeders
 * directory was not autoloaded in development, so nothing here could reach the
 * class at all.
 *
 * Two things were wrong once it could be. It handed tenant_id to
 * updateOrCreate, which mass assigns it — and tenant_id is deliberately not
 * fillable on the role model, precisely so that no request can choose which
 * tenant a row lands in. An application running Eloquent strictly gets a
 * MassAssignmentException out of `db:seed`; one that does not gets the value
 * discarded. And with it discarded, the tenancy stamp that fires on create
 * decides instead — so a seeder run while a tenant was current would write that
 * tenant's own roles under the name of the application's defaults.
 */
class DefaultRolesSeederTest extends OrchestraTestCase
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

        Jetstream::$roles = [];

        Jetstream::role('support', 'Support', ['read'])->description('Answers questions.');
    }

    protected function tearDown(): void
    {
        Jetstream::$roles = $this->registeredRoles;

        parent::tearDown();
    }

    protected function createTenant(): Tenant
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        return Tenant::forceCreate(['name' => 'Acme', 'slug' => 'acme', 'user_id' => $owner->id]);
    }

    public function test_the_seeder_copies_the_role_catalog_into_the_table(): void
    {
        (new DefaultRolesSeeder)->run();

        $row = DB::table('roles')->where('key', 'support')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->tenant_id);
        $this->assertSame('Support', $row->name);
        $this->assertSame('Answers questions.', $row->description);
        $this->assertSame(['read'], json_decode((string) $row->permissions, true));
    }

    public function test_the_seeder_updates_a_default_whose_catalog_entry_has_changed(): void
    {
        (new DefaultRolesSeeder)->run();

        Jetstream::$roles = [];

        Jetstream::role('support', 'Support Team', ['read', 'update']);

        (new DefaultRolesSeeder)->run();

        $this->assertSame(1, DB::table('roles')->where('key', 'support')->count());

        $row = DB::table('roles')->where('key', 'support')->first();

        $this->assertSame('Support Team', $row->name);
        $this->assertSame(['read', 'update'], json_decode((string) $row->permissions, true));
    }

    public function test_the_seeder_writes_application_defaults_even_when_a_tenant_is_current(): void
    {
        // The stamp that puts a tenant on a new row fires on create, and it
        // reads whatever tenant is current — which is not a property of the
        // rows this seeder writes. Left to it, `db:seed` inside a tenant
        // context would file the application's defaults as one tenant's roles,
        // where no other tenant can see them and the tenant that got them never
        // asked for them.
        $tenant = $this->createTenant();

        app(TenantContext::class)->runFor($tenant, function (): void {
            (new DefaultRolesSeeder)->run();
        });

        $this->assertSame(
            1,
            DB::table('roles')->whereNull('tenant_id')->where('key', 'support')->count(),
            'The application default was filed under the tenant that happened to be current.'
        );

        $this->assertSame(0, DB::table('roles')->whereNotNull('tenant_id')->count());
    }

    public function test_the_same_holds_for_an_application_that_does_not_run_eloquent_strictly(): void
    {
        // The two defects hid each other. This suite runs Eloquent strictly, so
        // handing tenant_id to updateOrCreate threw before the tenancy stamp
        // could get a word in — which means the strict run above cannot tell
        // whether the stamp was dealt with or merely never reached.
        //
        // Most applications are not strict. There the value is discarded in
        // silence and the stamp decides, so this is where filing the defaults
        // under a tenant actually happens.
        $tenant = $this->createTenant();

        Model::preventSilentlyDiscardingAttributes(false);

        try {
            app(TenantContext::class)->runFor($tenant, function (): void {
                (new DefaultRolesSeeder)->run();
            });
        } finally {
            Model::preventSilentlyDiscardingAttributes();
        }

        $this->assertSame(
            1,
            DB::table('roles')->whereNull('tenant_id')->where('key', 'support')->count(),
            'The application default was filed under the tenant that happened to be current.'
        );

        $this->assertSame(0, DB::table('roles')->whereNotNull('tenant_id')->count());
    }

    public function test_the_tenant_a_role_belongs_to_is_still_not_mass_assignable(): void
    {
        // What the seeder had to stop doing, and why it is not fixed by adding
        // tenant_id to the model's fillable list: keeping it out is what stops
        // a request choosing which tenant a role lands in.
        $this->assertNotContains('tenant_id', (new Role)->getFillable());

        // Handing it over is refused outright when the application runs
        // Eloquent strictly, as this suite does, and discarded when it does
        // not. Either way the caller does not get to say.
        $this->expectException(MassAssignmentException::class);

        Role::create([
            'tenant_id' => null,
            'key' => 'support',
            'name' => 'Support',
            'permissions' => ['read'],
        ]);
    }

    public function test_the_tenant_a_role_belongs_to_is_decided_by_the_context_it_is_created_in(): void
    {
        // The other side of that: not being able to say does not mean the
        // column goes unset. Tenancy stamps it, from whatever tenant is
        // current — which is exactly why the seeder cannot simply leave it
        // alone and hope.
        $tenant = $this->createTenant();

        $role = app(TenantContext::class)->runFor($tenant, fn (): Role => Role::create([
            'key' => 'support',
            'name' => 'Support',
            'permissions' => ['read'],
        ]));

        $this->assertSame($tenant->id, $role->tenant_id);
    }
}

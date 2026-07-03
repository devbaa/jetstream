<?php

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class AdminAccessTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Jetstream::useUserModel(User::class);
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function defineRoutes($router)
    {
        $router->get('/probe/admin', function () {
            return response()->json(['ok' => true]);
        })->middleware(['web', 'auth', 'system.admin'])->name('probe.admin');
    }

    public function test_regular_users_cannot_access_admin_routes()
    {
        $user = User::forceCreate([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($user)->get('/probe/admin')->assertStatus(403);
        $this->actingAs($user)->get('/admin/tenants')->assertStatus(403);
    }

    public function test_system_admins_can_access_admin_routes()
    {
        $admin = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'is_system_admin' => true,
        ]);

        $this->actingAs($admin)->get('/probe/admin')->assertOk();
    }

    public function test_scope_bypass_allows_cross_tenant_administration()
    {
        $ownerA = User::forceCreate([
            'name' => 'Owner A', 'email' => 'a@example.com', 'password' => 'secret',
        ]);

        $ownerB = User::forceCreate([
            'name' => 'Owner B', 'email' => 'b@example.com', 'password' => 'secret',
        ]);

        $tenantA = (new CreateTenant)->create($ownerA, ['name' => 'Tenant A']);
        $tenantB = (new CreateTenant)->create($ownerB, ['name' => 'Tenant B']);

        CustomerAccount::forceCreate(['tenant_id' => $tenantA->id, 'user_id' => $ownerA->id, 'name' => 'In A']);
        CustomerAccount::forceCreate(['tenant_id' => $tenantB->id, 'user_id' => $ownerB->id, 'name' => 'In B']);

        $context = app(TenantContext::class);

        $context->set($tenantA);

        $this->assertSame(1, CustomerAccount::count());

        $this->assertSame(2, $context->bypass(function () {
            return CustomerAccount::count();
        }));
    }
}

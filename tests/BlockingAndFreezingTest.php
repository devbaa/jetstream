<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class BlockingAndFreezingTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        Jetstream::useUserModel(User::class);
    }

    protected function defineRoutes($router)
    {
        $router->get('/blocked-probe', fn () => response('ok'))
            ->middleware(['web', 'auth', 'account.active']);

        $router->get('/tenant-probe', function () {
            return response('tenant:'.(app(\Laravel\Jetstream\Tenancy\TenantContext::class)->currentId() ?? 'none'));
        })->middleware(['web', 'auth', 'tenant.context']);
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    protected function createTenantFor(User $owner, string $slug = 'acme'): Tenant
    {
        return Tenant::forceCreate([
            'user_id' => $owner->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    public function test_blocked_users_are_logged_out_and_turned_away(): void
    {
        $user = $this->createUser();

        $user->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Abuse'])->save();

        $this->actingAs($user);

        $response = $this->get('/blocked-probe');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unblocked_users_pass_through(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        $this->get('/blocked-probe')->assertOk();
    }

    public function test_a_frozen_tenant_is_inaccessible_to_its_members(): void
    {
        $owner = $this->createUser();
        $tenant = $this->createTenantFor($owner);

        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

        $tenant->forceFill(['frozen_at' => now()])->save();

        $this->actingAs($owner);

        $this->get('/tenant-probe')->assertSee('tenant:none');

        // The stale selection is healed away.
        $this->assertNull($owner->fresh()->current_tenant_id);
    }

    public function test_a_frozen_tenant_cannot_be_switched_to(): void
    {
        $owner = $this->createUser();
        $tenant = $this->createTenantFor($owner);

        $tenant->forceFill(['frozen_at' => now()])->save();

        $this->assertFalse($owner->switchTenant($tenant));

        $tenant->forceFill(['frozen_at' => null])->save();

        $this->assertTrue($owner->fresh()->switchTenant($tenant->fresh()));
    }

    public function test_frozen_tenants_deny_all_permissions(): void
    {
        $owner = $this->createUser();
        $tenant = $this->createTenantFor($owner);

        $this->assertTrue($owner->hasTenantPermission($tenant, 'tenant:update'));

        $tenant->forceFill(['frozen_at' => now()])->save();

        $this->assertFalse($owner->fresh()->hasTenantPermission($tenant->fresh(), 'tenant:update'));
    }

    public function test_a_frozen_staff_membership_loses_access(): void
    {
        $owner = $this->createUser();
        $staff = $this->createUser('adam@laravel.com');

        $tenant = $this->createTenantFor($owner);

        \App\Models\Role::forceCreate([
            'tenant_id' => null, 'key' => 'admin', 'name' => 'Admin', 'permissions' => ['tenant:update'],
        ]);

        $staff->tenants()->attach($tenant, ['role' => 'admin']);

        $this->assertTrue($staff->fresh()->hasActiveTenantAccess($tenant));
        $this->assertTrue($staff->fresh()->hasTenantPermission($tenant, 'tenant:update'));

        $tenant->users()->updateExistingPivot($staff->id, ['frozen_at' => now()]);

        $staff = $staff->fresh();

        $this->assertTrue($staff->tenantMembershipIsFrozen($tenant));
        $this->assertFalse($staff->hasActiveTenantAccess($tenant));
        $this->assertFalse($staff->hasTenantPermission($tenant, 'tenant:update'));
        $this->assertFalse($staff->switchTenant($tenant));

        // The owner remains unaffected.
        $this->assertTrue($owner->hasActiveTenantAccess($tenant));
    }

    public function test_a_frozen_customer_account_cannot_be_switched_to(): void
    {
        $owner = $this->createUser();
        $customer = $this->createUser('customer@laravel.com');

        $tenant = $this->createTenantFor($owner);

        $account = CustomerAccount::forceCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'name' => 'Customer',
        ]);

        $this->assertTrue($customer->switchCustomerAccount($account));

        $account->forceFill(['frozen_at' => now()])->save();

        $customer = $customer->fresh();

        $this->assertFalse($customer->hasActiveCustomerAccountAccess($account->fresh()));
        $this->assertFalse($customer->switchCustomerAccount($account->fresh()));
    }

    public function test_an_account_of_a_frozen_tenant_is_inaccessible(): void
    {
        $owner = $this->createUser();
        $customer = $this->createUser('customer@laravel.com');

        $tenant = $this->createTenantFor($owner);

        $account = CustomerAccount::forceCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'name' => 'Customer',
        ]);

        $this->assertTrue($customer->hasActiveCustomerAccountAccess($account));

        $tenant->forceFill(['frozen_at' => now()])->save();

        $this->assertFalse($customer->fresh()->hasActiveCustomerAccountAccess($account->fresh()));
    }

    public function test_the_block_middleware_alias_is_registered(): void
    {
        $this->assertArrayHasKey('account.active', Route::getMiddleware());
    }
}

<?php

namespace Laravel\Jetstream\Tests;

use App\Models\CustomerAccount;
use App\Models\Role;
use App\Models\Tenant;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\User;

class TenantScopeTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Jetstream::useUserModel(User::class);
    }

    protected function createTenants(): array
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenantA = Tenant::forceCreate(['name' => 'Tenant A', 'slug' => 'tenant-a', 'user_id' => $owner->id]);
        $tenantB = Tenant::forceCreate(['name' => 'Tenant B', 'slug' => 'tenant-b', 'user_id' => $owner->id]);

        CustomerAccount::forceCreate(['tenant_id' => $tenantA->id, 'user_id' => $owner->id, 'name' => 'Account A']);
        CustomerAccount::forceCreate(['tenant_id' => $tenantB->id, 'user_id' => $owner->id, 'name' => 'Account B']);

        return [$owner, $tenantA, $tenantB];
    }

    public function test_tenant_scoped_models_are_isolated_by_the_current_context()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $context = app(TenantContext::class);

        // Empty context runs unscoped...
        $this->assertSame(2, CustomerAccount::count());

        $context->set($tenantA);

        $this->assertSame(1, CustomerAccount::count());
        $this->assertSame('Account A', CustomerAccount::first()->name);

        $context->set($tenantB);

        $this->assertSame(1, CustomerAccount::count());
        $this->assertSame('Account B', CustomerAccount::first()->name);

        $context->forget();

        $this->assertSame(2, CustomerAccount::count());
    }

    public function test_creating_models_in_context_fills_the_tenant_id()
    {
        [$owner, $tenantA] = $this->createTenants();

        app(TenantContext::class)->set($tenantA);

        $account = CustomerAccount::forceCreate(['user_id' => $owner->id, 'name' => 'Implicit']);

        $this->assertEquals($tenantA->id, $account->tenant_id);
    }

    public function test_scope_can_be_bypassed_explicitly()
    {
        [$owner, $tenantA] = $this->createTenants();

        $context = app(TenantContext::class);

        $context->set($tenantA);

        $this->assertSame(2, CustomerAccount::withoutTenancy()->count());

        $this->assertSame(2, $context->bypass(function () {
            return CustomerAccount::count();
        }));

        // Bypass is restored afterwards...
        $this->assertSame(1, CustomerAccount::count());
    }

    public function test_run_for_executes_within_the_given_tenant_context()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $context = app(TenantContext::class);

        $names = $context->runFor($tenantB, function () {
            return CustomerAccount::pluck('name')->all();
        });

        $this->assertSame(['Account B'], $names);
        $this->assertNull($context->currentId());
    }

    public function test_tenant_optional_models_include_rows_without_a_tenant()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        Role::forceCreate(['tenant_id' => null, 'key' => 'staff', 'name' => 'Staff', 'permissions' => ['read']]);
        Role::forceCreate(['tenant_id' => $tenantA->id, 'key' => 'agent', 'name' => 'Agent', 'permissions' => ['read']]);
        Role::forceCreate(['tenant_id' => $tenantB->id, 'key' => 'runner', 'name' => 'Runner', 'permissions' => ['read']]);

        app(TenantContext::class)->set($tenantA);

        $this->assertEqualsCanonicalizing(['staff', 'agent'], Role::pluck('key')->all());
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\CustomerAccount;
use App\Models\Role;
use App\Models\Tenant;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\MissingTenantContextException;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\User;
use RuntimeException;

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

        $context->set($tenantA);

        $this->assertSame(1, CustomerAccount::count());
        $this->assertSame('Account A', CustomerAccount::first()->name);

        $context->set($tenantB);

        $this->assertSame(1, CustomerAccount::count());
        $this->assertSame('Account B', CustomerAccount::first()->name);
    }

    public function test_tenant_scoped_query_throws_when_no_tenant_is_in_context()
    {
        $this->createTenants();

        $this->expectException(MissingTenantContextException::class);

        CustomerAccount::count();
    }

    public function test_missing_tenant_context_exception_identifies_the_model_and_the_escape_hatches()
    {
        $this->createTenants();

        try {
            CustomerAccount::query()->get();

            $this->fail('A tenant scoped query without a tenant context should have thrown.');
        } catch (MissingTenantContextException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertStringContainsString(CustomerAccount::class, $e->getMessage());
            $this->assertStringContainsString('No tenant context is active', $e->getMessage());
            $this->assertStringContainsString('withoutTenancy()', $e->getMessage());
        }
    }

    public function test_forgetting_the_tenant_returns_the_model_to_failing_closed()
    {
        [$owner, $tenantA] = $this->createTenants();

        $context = app(TenantContext::class);

        $context->set($tenantA);

        $this->assertSame(1, CustomerAccount::count());

        $context->forget();

        $this->expectException(MissingTenantContextException::class);

        CustomerAccount::count();
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

    public function test_without_tenancy_removes_the_scope_for_a_single_query()
    {
        $this->createTenants();

        $this->assertSame(2, CustomerAccount::withoutTenancy()->count());

        // The next query is scoped again, and there is still no context...
        $this->expectException(MissingTenantContextException::class);

        CustomerAccount::count();
    }

    public function test_bypass_allows_a_cross_tenant_query_without_a_tenant_context()
    {
        $this->createTenants();

        $names = app(TenantContext::class)->bypass(function () {
            return CustomerAccount::pluck('name')->all();
        });

        $this->assertEqualsCanonicalizing(['Account A', 'Account B'], $names);
    }

    public function test_bypass_restores_the_previous_state_after_an_exception()
    {
        $this->createTenants();

        $context = app(TenantContext::class);

        try {
            $context->bypass(function () {
                throw new RuntimeException('Boom');
            });
        } catch (RuntimeException) {
            // Intentionally swallowed...
        }

        $this->assertFalse($context->shouldBypass());

        $this->expectException(MissingTenantContextException::class);

        CustomerAccount::count();
    }

    public function test_nested_bypasses_restore_the_outer_bypass()
    {
        $this->createTenants();

        $context = app(TenantContext::class);

        $context->bypass(function () use ($context) {
            $context->bypass(function () use ($context) {
                $this->assertTrue($context->shouldBypass());
            });

            // The inner bypass restored the outer one rather than clearing it...
            $this->assertTrue($context->shouldBypass());
            $this->assertSame(2, CustomerAccount::count());
        });

        $this->assertFalse($context->shouldBypass());
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

    public function test_run_for_restores_the_previous_tenant()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $context = app(TenantContext::class);

        $context->set($tenantA);

        $context->runFor($tenantB, function () use ($context, $tenantB) {
            $this->assertSame($tenantB->id, $context->currentId());
            $this->assertSame('Account B', CustomerAccount::first()->name);
        });

        $this->assertSame($tenantA->id, $context->currentId());
        $this->assertSame('Account A', CustomerAccount::first()->name);
    }

    public function test_run_for_restores_the_previous_tenant_after_an_exception()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $context = app(TenantContext::class);

        $context->set($tenantA);

        try {
            $context->runFor($tenantB, function () {
                throw new RuntimeException('Boom');
            });
        } catch (RuntimeException) {
            // Intentionally swallowed...
        }

        $this->assertSame($tenantA->id, $context->currentId());
        $this->assertSame('Account A', CustomerAccount::first()->name);
    }

    public function test_tenant_optional_models_include_rows_without_a_tenant()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $this->createRoles($tenantA, $tenantB);

        app(TenantContext::class)->set($tenantA);

        $this->assertEqualsCanonicalizing(['staff', 'agent'], Role::pluck('key')->all());
    }

    public function test_tenant_optional_models_also_fail_closed_without_a_tenant_context()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $this->createRoles($tenantA, $tenantB);

        // A tenant-optional model is not a weaker scope: without a context it
        // must never answer with rows belonging to more than one tenant.
        $this->expectException(MissingTenantContextException::class);

        Role::pluck('key');
    }

    public function test_global_default_rows_are_reached_through_an_explicit_unscoped_query()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $this->createRoles($tenantA, $tenantB);

        $keys = Role::withoutTenancy()->whereNull('tenant_id')->pluck('key')->all();

        $this->assertSame(['staff'], $keys);
    }

    public function test_console_style_execution_cannot_expose_every_tenant_by_accident()
    {
        $this->createTenants();

        // A console command or scheduler runs with no context at all...
        $this->assertNull(app(TenantContext::class)->currentId());

        $this->expectException(MissingTenantContextException::class);

        CustomerAccount::query()->get();
    }

    public function test_background_work_must_establish_a_tenant_context_explicitly()
    {
        [$owner, $tenantA, $tenantB] = $this->createTenants();

        $context = app(TenantContext::class);

        $job = function () {
            return CustomerAccount::pluck('name')->all();
        };

        // Dispatched without its tenant, the job fails loudly...
        try {
            $job();

            $this->fail('A queued job without a tenant context should have thrown.');
        } catch (MissingTenantContextException) {
            // Expected...
        }

        // Wrapped in the tenant it belongs to, it sees only that tenant...
        $this->assertSame(['Account B'], $context->runFor($tenantB, $job));

        // And the context does not leak past the job...
        $this->assertNull($context->currentId());
    }

    protected function createRoles(Tenant $tenantA, Tenant $tenantB): void
    {
        Role::forceCreate(['tenant_id' => null, 'key' => 'staff', 'name' => 'Staff', 'permissions' => ['read']]);
        Role::forceCreate(['tenant_id' => $tenantA->id, 'key' => 'agent', 'name' => 'Agent', 'permissions' => ['read']]);
        Role::forceCreate(['tenant_id' => $tenantB->id, 'key' => 'runner', 'name' => 'Runner', 'permissions' => ['read']]);
    }
}

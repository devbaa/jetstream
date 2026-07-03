<?php

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTenant;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\CustomerContext;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class PortalAccessTest extends OrchestraTestCase
{
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
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function defineRoutes($router)
    {
        // Probe routes exposing the resolved contexts, since the shipped
        // portal views are not available within the package test suite.
        $router->get('/probe/portal', function () {
            return response()->json([
                'account' => app(CustomerContext::class)->currentId(),
                'tenant' => app(TenantContext::class)->currentId(),
            ]);
        })->middleware(['web', 'auth', 'customer.context'])->name('probe.portal');

        $router->get('/probe/staff', function () {
            return response()->json([
                'tenant' => app(TenantContext::class)->currentId(),
            ]);
        })->middleware(['web', 'auth', 'tenant.context'])->name('probe.staff');
    }

    protected function createTenantWithOwner(string $suffix = ''): array
    {
        $owner = User::forceCreate([
            'name' => 'Owner '.$suffix,
            'email' => 'owner'.$suffix.'@example.com',
            'password' => 'secret',
        ]);

        return [$owner, (new CreateTenant)->create($owner, ['name' => 'Tenant '.$suffix])];
    }

    public function test_users_without_customer_accounts_cannot_use_portal_routes()
    {
        $user = User::forceCreate([
            'name' => 'No Accounts',
            'email' => 'none@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($user)->get('/probe/portal')->assertStatus(403);
    }

    public function test_a_single_customer_account_is_selected_automatically()
    {
        [$owner, $tenant] = $this->createTenantWithOwner('a');

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        $response = $this->actingAs($customer->fresh())->get('/probe/portal');

        $response->assertOk();
        $response->assertJson(['account' => $account->id, 'tenant' => $tenant->id]);

        $this->assertEquals($account->id, $customer->fresh()->current_customer_account_id);
    }

    public function test_customers_can_switch_between_accounts()
    {
        [$ownerA, $tenantA] = $this->createTenantWithOwner('a');
        [$ownerB, $tenantB] = $this->createTenantWithOwner('b');

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $accountA = (new CreateCustomerAccount)->create($tenantA, $customer, ['name' => 'In A']);
        $accountB = (new CreateCustomerAccount)->create($tenantB, $customer, ['name' => 'In B']);

        $customer->fresh()->switchCustomerAccount($accountA);

        $this->actingAs($customer->fresh())
            ->get('/probe/portal')
            ->assertJson(['account' => $accountA->id, 'tenant' => $tenantA->id]);

        $this->actingAs($customer->fresh())
            ->put('/portal/current-account', ['customer_account_id' => $accountB->id])
            ->assertRedirect(route('portal.show'));

        $this->actingAs($customer->fresh())
            ->get('/probe/portal')
            ->assertJson(['account' => $accountB->id, 'tenant' => $tenantB->id]);
    }

    public function test_customers_cannot_switch_to_accounts_they_do_not_belong_to()
    {
        [$owner, $tenant] = $this->createTenantWithOwner('a');

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        $outsider = User::forceCreate([
            'name' => 'Out Sider',
            'email' => 'out@example.com',
            'password' => 'secret',
        ]);

        $outsiderAccount = (new CreateCustomerAccount)->create($tenant, $outsider, ['name' => 'Out Co']);

        $this->actingAs($outsider->fresh())
            ->put('/portal/current-account', ['customer_account_id' => $account->id])
            ->assertStatus(403);
    }

    public function test_staff_and_customer_contexts_resolve_independently_for_the_same_user()
    {
        [$ownerA, $tenantA] = $this->createTenantWithOwner('a');

        // The owner of tenant A becomes a customer of tenant B...
        [$ownerB, $tenantB] = $this->createTenantWithOwner('b');

        $account = (new CreateCustomerAccount)->create($tenantB, $ownerA, ['name' => 'A as customer of B']);

        $ownerA = $ownerA->fresh();
        $ownerA->switchCustomerAccount($account);

        $this->actingAs($ownerA->fresh())
            ->get('/probe/staff')
            ->assertJson(['tenant' => $tenantA->id]);

        $this->actingAs($ownerA->fresh())
            ->get('/probe/portal')
            ->assertJson(['account' => $account->id, 'tenant' => $tenantB->id]);
    }

    public function test_customers_can_self_register_when_the_tenant_allows_it()
    {
        [$owner, $tenant] = $this->createTenantWithOwner('a');

        $tenant->forceFill(['allow_customer_registration' => true])->save();

        $user = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $response = $this->actingAs($user)->post('/portal/register/'.$tenant->slug);

        $response->assertRedirect(route('portal.show'));

        $this->assertTrue($user->fresh()->isCustomerOf($tenant));

        // Registering twice does not create a second account...
        $this->actingAs($user->fresh())->post('/portal/register/'.$tenant->slug);

        $this->assertSame(1, CustomerAccount::withoutTenancy()->where('user_id', $user->id)->count());
    }

    public function test_self_registration_is_rejected_when_the_tenant_disallows_it()
    {
        [$owner, $tenant] = $this->createTenantWithOwner('a');

        $user = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($user)
            ->post('/portal/register/'.$tenant->slug)
            ->assertStatus(404);

        $this->assertFalse($user->fresh()->isCustomerOf($tenant));
    }
}

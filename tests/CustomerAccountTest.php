<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTenant;
use App\Actions\Jetstream\InviteCustomer;
use App\Actions\Jetstream\RemoveCustomerAccountMember;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\CustomerInvitation;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class CustomerAccountTest extends OrchestraTestCase
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

    protected function createOwnerAndTenant(): array
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        return [$owner, (new CreateTenant)->create($owner, ['name' => 'Acme'])];
    }

    public function test_customer_account_relationship_methods()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        $customer = $customer->fresh();

        $this->assertTrue($customer->ownsCustomerAccount($account));
        $this->assertTrue($customer->belongsToCustomerAccount($account));
        $this->assertTrue($customer->isCustomerOf($tenant));
        $this->assertFalse($customer->isCustomerOf(Tenant::forceCreate(['name' => 'Other', 'slug' => 'other', 'user_id' => $owner->id])));
        $this->assertCount(1, $customer->allCustomerAccounts());

        $this->assertTrue($customer->switchCustomerAccount($account));
        $this->assertEquals($account->id, $customer->fresh()->current_customer_account_id);

        // The same user can be staff of one tenant and customer of another...
        $ownTenant = (new CreateTenant)->create($customer->fresh(), ['name' => 'Janes Tenant']);

        $customer = $customer->fresh();

        $this->assertTrue($customer->ownsTenant($ownTenant));
        $this->assertTrue($customer->isCustomerOf($tenant));
        $this->assertEquals($ownTenant->id, $customer->current_tenant_id);
        $this->assertEquals($account->id, $customer->current_customer_account_id);
    }

    public function test_customers_can_be_invited_without_an_account()
    {
        Mail::fake();

        [$owner, $tenant] = $this->createOwnerAndTenant();

        (new InviteCustomer)->invite($owner, $tenant, 'jane@example.com');

        Mail::assertSent(CustomerInvitation::class);

        $invitation = $tenant->customerInvitations()->first();

        $this->assertNotNull($invitation);
        $this->assertNull($invitation->customer_account_id);

        // Accepting creates a fresh account owned by the invitee...
        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $url = URL::signedRoute('customer-invitations.accept', ['invitation' => $invitation]);

        $response = $this->actingAs($customer)->get($url);

        $response->assertRedirect(route('portal.show'));

        $customer = $customer->fresh();

        $this->assertTrue($customer->isCustomerOf($tenant));
        $this->assertNotNull($customer->current_customer_account_id);
        $this->assertNull($invitation->fresh());
    }

    public function test_customers_can_invite_members_into_their_account()
    {
        Mail::fake();

        [$owner, $tenant] = $this->createOwnerAndTenant();

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        (new InviteCustomer)->invite($customer->fresh(), $tenant, 'mate@example.com', $account);

        Mail::assertSent(CustomerInvitation::class);

        $invitation = $account->customerInvitations()->withoutTenancy()->first();

        $this->assertEquals($account->id, $invitation->customer_account_id);

        $mate = User::forceCreate([
            'name' => 'Mate Member',
            'email' => 'mate@example.com',
            'password' => 'secret',
        ]);

        $url = URL::signedRoute('customer-invitations.accept', ['invitation' => $invitation]);

        $this->actingAs($mate)->get($url)->assertRedirect(route('portal.show'));

        $mate = $mate->fresh();

        $this->assertTrue($mate->belongsToCustomerAccount($account));
        $this->assertFalse($mate->ownsCustomerAccount($account));

        // Members can be removed by the owner...
        (new RemoveCustomerAccountMember)->remove($customer->fresh(), $account->fresh(), $mate);

        $this->assertFalse($mate->fresh()->belongsToCustomerAccount($account->fresh()));
    }

    public function test_account_owners_cannot_be_removed_from_their_account()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        $this->expectException(ValidationException::class);

        (new RemoveCustomerAccountMember)->remove($customer->fresh(), $account, $customer->fresh());
    }

    public function test_outsiders_cannot_invite_customers()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $outsider = User::forceCreate([
            'name' => 'Out Sider',
            'email' => 'out@example.com',
            'password' => 'secret',
        ]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        (new InviteCustomer)->invite($outsider, $tenant, 'someone@example.com');
    }
}

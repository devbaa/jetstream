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
use Laravel\Jetstream\Http\Livewire\Portal\AccountMemberManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\CustomerInvitation;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use Laravel\Jetstream\Tests\Fixtures\WidenedCustomerAccountPolicy;
use Livewire\Livewire;

class PortalAccountMemberManagerTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(CustomerAccount::class, CustomerAccountPolicy::class);
        Jetstream::useUserModel(User::class);
        Jetstream::createCustomerAccountsUsing(CreateCustomerAccount::class);
        Jetstream::inviteCustomersUsing(InviteCustomer::class);
        Jetstream::removeCustomerAccountMembersUsing(RemoveCustomerAccountMember::class);
    }

    /**
     * Create a tenant with a customer account and return the account's owner and the account.
     *
     * @return array{0: User, 1: CustomerAccount}
     */
    protected function createAccountWithOwner(): array
    {
        $tenantOwner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($tenantOwner, ['name' => 'Acme']);

        $customer = User::forceCreate([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        $account = (new CreateCustomerAccount)->create($tenant, $customer, ['name' => 'Jane Co']);

        // The portal runs behind the "customer.context" middleware, which
        // derives the tenant context from the selected account. Tenant scoped
        // queries fail closed without it, so mirror that here.
        app(TenantContext::class)->set($tenant);

        return [$customer->fresh(), $account];
    }

    protected function createMember(CustomerAccount $account, string $email = 'mate@example.com'): User
    {
        $member = User::forceCreate([
            'name' => 'Mate Member',
            'email' => $email,
            'password' => 'secret',
        ]);

        $account->users()->attach($member);

        return $member;
    }

    public function test_account_owners_can_invite_members_from_the_portal(): void
    {
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        Mail::assertSent(CustomerInvitation::class);

        $invitation = $account->customerInvitations()->withoutTenancy()->first();

        $this->assertNotNull($invitation);
        $this->assertSame('mate@example.com', $invitation->email);
    }

    public function test_members_cannot_be_invited_twice(): void
    {
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        $component = Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasNoErrors();

        $component
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasErrors(['email']);

        $this->assertSame(1, $account->customerInvitations()->withoutTenancy()->count());
    }

    public function test_pending_invitations_can_be_cancelled(): void
    {
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember');

        $invitation = $account->customerInvitations()->withoutTenancy()->firstOrFail();

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('cancelInvitation', $invitation->id);

        $this->assertNull($invitation->fresh());
    }

    public function test_an_ordinary_member_cannot_cancel_an_invitation(): void
    {
        // The blade hides the cancel button behind Gate::check('addMember'),
        // which is not authorization: the Livewire method is a public server
        // endpoint and anyone who can reach the component can call it with an
        // invitation id.
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasNoErrors();

        $invitation = $account->customerInvitations()->withoutTenancy()->firstOrFail();

        // A member of the account who does not own it. They can see the
        // account; they may not manage its membership.
        $member = $this->createMember($account, 'bystander@example.com');

        $this->assertFalse(Gate::forUser($member)->check('addMember', $account));

        $this->actingAs($member);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('cancelInvitation', $invitation->id)
            ->assertForbidden();

        $this->assertTrue(
            $account->customerInvitations()->withoutTenancy()->whereKey($invitation->id)->exists(),
            'The invitation was cancelled by a member who may not manage membership.'
        );
    }

    public function test_widening_the_add_member_policy_does_not_widen_cancelling(): void
    {
        // The check must not be spelled through the "addMember" ability. That
        // ability is defined in a policy the application owns and may change;
        // if it did, without also changing its InviteCustomer action, members
        // could cancel invitations they are not allowed to create.
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasNoErrors();

        $invitation = $account->customerInvitations()->withoutTenancy()->firstOrFail();

        $member = $this->createMember($account, 'bystander@example.com');

        Gate::policy(CustomerAccount::class, WidenedCustomerAccountPolicy::class);

        // The application now says this member may add members...
        $this->assertTrue(Gate::forUser($member)->check('addMember', $account));

        $this->actingAs($member);

        // ...and cancelling is still refused, because it does not ask.
        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('cancelInvitation', $invitation->id)
            ->assertForbidden();

        $this->assertTrue(
            $account->customerInvitations()->withoutTenancy()->whereKey($invitation->id)->exists()
        );
    }

    public function test_tenant_staff_who_may_invite_can_also_cancel(): void
    {
        // Whoever may create an invitation may withdraw it. Invitation
        // creation allows the account owner or tenant staff holding
        // "manageCustomers", so cancelling answers to the same rule rather
        // than to the narrower one the button happens to be hidden behind.
        Mail::fake();

        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->set('addMemberForm.email', 'mate@example.com')
            ->call('addMember')
            ->assertHasNoErrors();

        $invitation = $account->customerInvitations()->withoutTenancy()->firstOrFail();

        $tenantOwner = $account->tenant()->firstOrFail()->owner()->firstOrFail();

        $this->assertTrue(Gate::forUser($tenantOwner)->check('manageCustomers', $account->tenant));

        $this->actingAs($tenantOwner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('cancelInvitation', $invitation->id);

        $this->assertFalse(
            $account->customerInvitations()->withoutTenancy()->whereKey($invitation->id)->exists()
        );
    }

    public function test_account_owners_can_remove_members(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $member = $this->createMember($account);

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('confirmMemberRemoval', $member->id)
            ->assertSet('confirmingMemberRemoval', true)
            ->call('removeMember')
            ->assertSet('confirmingMemberRemoval', false)
            ->assertSet('memberIdBeingRemoved', null);

        $this->assertFalse($member->fresh()->belongsToCustomerAccount($account->fresh()));
    }

    public function test_members_cannot_be_removed_without_confirmation(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $this->createMember($account);

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('removeMember')
            ->assertStatus(403);
    }

    public function test_members_can_leave_the_account(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $member = $this->createMember($account);

        $this->actingAs($member);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('leaveAccount')
            ->assertRedirect(route('portal.show'));

        $this->assertFalse($member->fresh()->belongsToCustomerAccount($account->fresh()));
    }

    public function test_account_owners_cannot_leave_their_own_account(): void
    {
        [$owner, $account] = $this->createAccountWithOwner();

        $this->actingAs($owner);

        Livewire::test(AccountMemberManager::class, ['account' => $account])
            ->call('leaveAccount')
            ->assertHasErrors(['account']);

        $this->assertTrue($owner->fresh()->ownsCustomerAccount($account->fresh()));
    }
}

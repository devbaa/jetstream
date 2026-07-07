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
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
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

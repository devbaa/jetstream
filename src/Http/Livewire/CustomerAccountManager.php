<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\Contracts\DeletesCustomerAccounts;
use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class CustomerAccountManager extends Component
{
    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * The "invite customer" form state.
     *
     * @var array{email: string}
     */
    public $inviteCustomerForm = [
        'email' => '',
    ];

    /**
     * Indicates if the application is confirming a customer account deletion.
     *
     * @var bool
     */
    public $confirmingAccountDeletion = false;

    /**
     * The ID of the customer account being deleted.
     *
     * @var string|null
     */
    public $accountIdBeingDeleted = null;

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @return void
     */
    public function mount($tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Invite a new customer to the tenant.
     *
     * @return void
     */
    public function inviteCustomer(InvitesCustomers $inviter)
    {
        $this->resetErrorBag();

        $inviter->invite(
            $this->user,
            $this->tenant,
            $this->inviteCustomerForm['email']
        );

        $this->inviteCustomerForm = [
            'email' => '',
        ];

        $this->tenant->refresh();

        $this->dispatch('saved');
    }

    /**
     * Cancel a pending customer invitation.
     *
     * @param  string  $invitationId
     * @return void
     */
    public function cancelCustomerInvitation($invitationId)
    {
        if (! empty($invitationId)) {
            $this->tenant->customerInvitations()->whereKey($invitationId)->delete();
        }

        $this->tenant->refresh();
    }

    /**
     * Toggle whether the given customer account is frozen.
     *
     * A frozen account's members lose access to the customer portal for the
     * account until it is unfrozen.
     *
     * @param  string  $accountId
     * @return void
     */
    public function toggleAccountFreeze($accountId)
    {
        $account = $this->tenant->customerAccounts()->findOrFail($accountId);

        abort_unless($this->user->can('update', $account), 403);

        $account->forceFill([
            'frozen_at' => $account->isFrozen() ? null : now(),
        ])->save();

        $this->tenant->refresh();

        $this->dispatch('saved');
    }

    /**
     * Confirm that the given customer account should be deleted.
     *
     * @param  string  $accountId
     * @return void
     */
    public function confirmAccountDeletion($accountId)
    {
        $this->confirmingAccountDeletion = true;

        $this->accountIdBeingDeleted = $accountId;
    }

    /**
     * Delete the customer account that is being confirmed.
     *
     * @return void
     */
    public function deleteAccount(DeletesCustomerAccounts $deleter)
    {
        $account = $this->tenant->customerAccounts()->findOrFail($this->accountIdBeingDeleted);

        abort_unless($this->user->can('delete', $account), 403);

        $deleter->delete($account);

        $this->confirmingAccountDeletion = false;

        $this->accountIdBeingDeleted = null;

        $this->tenant->refresh();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Jetstream::currentUser();
    }

    /**
     * Get the tenant's customer accounts.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\CustomerAccount>
     */
    public function getAccountsProperty()
    {
        return $this->tenant->customerAccounts()->with('owner')->withCount('users')->get();
    }

    /**
     * Get the tenant's pending new-customer invitations.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\CustomerInvitation>
     */
    public function getPendingInvitationsProperty()
    {
        return $this->tenant->customerInvitations()->whereNull('customer_account_id')->get();
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('customers.customer-account-manager');
    }
}

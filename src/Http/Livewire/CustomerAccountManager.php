<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Contracts\DeletesCustomerAccounts;
use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class CustomerAccountManager extends Component
{
    /**
     * The tenant instance.
     *
     * @var mixed
     */
    public $tenant;

    /**
     * The "invite customer" form state.
     *
     * @var array
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
     * @var int|null
     */
    public $accountIdBeingDeleted = null;

    /**
     * Mount the component.
     *
     * @param  mixed  $tenant
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

        $this->tenant = $this->tenant->fresh();

        $this->dispatch('saved');
    }

    /**
     * Cancel a pending customer invitation.
     *
     * @param  int  $invitationId
     * @return void
     */
    public function cancelCustomerInvitation($invitationId)
    {
        if (! empty($invitationId)) {
            $this->tenant->customerInvitations()->whereKey($invitationId)->delete();
        }

        $this->tenant = $this->tenant->fresh();
    }

    /**
     * Confirm that the given customer account should be deleted.
     *
     * @param  int  $accountId
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

        $this->tenant = $this->tenant->fresh();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Auth::user();
    }

    /**
     * Get the tenant's customer accounts.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAccountsProperty()
    {
        return $this->tenant->customerAccounts()->with('owner')->withCount('users')->get();
    }

    /**
     * Get the tenant's pending new-customer invitations.
     *
     * @return \Illuminate\Database\Eloquent\Collection
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

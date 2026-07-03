<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Portal;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Contracts\RemovesCustomerAccountMembers;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class AccountMemberManager extends Component
{
    /**
     * The customer account instance.
     *
     * @var mixed
     */
    public $account;

    /**
     * Indicates if the application is confirming if a user wishes to leave the account.
     *
     * @var bool
     */
    public $confirmingLeavingAccount = false;

    /**
     * Indicates if the application is confirming if a member should be removed.
     *
     * @var bool
     */
    public $confirmingMemberRemoval = false;

    /**
     * The ID of the member being removed.
     *
     * @var int|null
     */
    public $memberIdBeingRemoved = null;

    /**
     * The "add member" form state.
     *
     * @var array
     */
    public $addMemberForm = [
        'email' => '',
    ];

    /**
     * Mount the component.
     *
     * @param  mixed  $account
     * @return void
     */
    public function mount($account)
    {
        $this->account = $account;
    }

    /**
     * Invite a new member to the customer account.
     *
     * @return void
     */
    public function addMember(InvitesCustomers $inviter)
    {
        $this->resetErrorBag();

        $inviter->invite(
            $this->user,
            $this->account->tenant()->first(),
            $this->addMemberForm['email'],
            $this->account
        );

        $this->addMemberForm = [
            'email' => '',
        ];

        $this->account = $this->account->fresh();

        $this->dispatch('saved');
    }

    /**
     * Cancel a pending member invitation.
     *
     * @param  int  $invitationId
     * @return void
     */
    public function cancelInvitation($invitationId)
    {
        if (! empty($invitationId)) {
            $this->account->customerInvitations()->whereKey($invitationId)->delete();
        }

        $this->account = $this->account->fresh();
    }

    /**
     * Remove the currently authenticated user from the account.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function leaveAccount(RemovesCustomerAccountMembers $remover)
    {
        $remover->remove(
            $this->user,
            $this->account,
            $this->user
        );

        $this->confirmingLeavingAccount = false;

        return redirect()->route('portal.show');
    }

    /**
     * Confirm that the given member should be removed.
     *
     * @param  int  $userId
     * @return void
     */
    public function confirmMemberRemoval($userId)
    {
        $this->confirmingMemberRemoval = true;

        $this->memberIdBeingRemoved = $userId;
    }

    /**
     * Remove a member from the customer account.
     *
     * @return void
     */
    public function removeMember(RemovesCustomerAccountMembers $remover)
    {
        $remover->remove(
            $this->user,
            $this->account,
            Jetstream::findUserByIdOrFail($this->memberIdBeingRemoved)
        );

        $this->confirmingMemberRemoval = false;

        $this->memberIdBeingRemoved = null;

        $this->account = $this->account->fresh();
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
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('portal.account-member-manager');
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Portal;

use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Contracts\RemovesCustomerAccountMembers;
use Laravel\Jetstream\Http\Livewire\Concerns\WithRateLimiting;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class AccountMemberManager extends Component
{
    use WithRateLimiting;

    /**
     * The customer account instance.
     *
     * @var \Laravel\Jetstream\CustomerAccount
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
     * @var string|null
     */
    public $memberIdBeingRemoved = null;

    /**
     * The "add member" form state.
     *
     * @var array{email: string}
     */
    public $addMemberForm = [
        'email' => '',
    ];

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\CustomerAccount  $account
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

        $this->rateLimit('account-member-invite', maxAttempts: 20, decaySeconds: 60);

        $inviter->invite(
            $this->user,
            $this->account->tenant()->firstOrFail(),
            $this->addMemberForm['email'],
            $this->account
        );

        $this->addMemberForm = [
            'email' => '',
        ];

        $this->account->refresh();

        $this->dispatch('saved');
    }

    /**
     * Cancel a pending member invitation.
     *
     * @param  string  $invitationId
     * @return void
     */
    public function cancelInvitation($invitationId)
    {
        if (! empty($invitationId)) {
            $this->account->customerInvitations()->whereKey($invitationId)->delete();
        }

        $this->account->refresh();
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
     * @param  string  $userId
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
        abort_if(is_null($this->memberIdBeingRemoved), 403);

        $remover->remove(
            $this->user,
            $this->account,
            Jetstream::findUserByIdOrFail($this->memberIdBeingRemoved)
        );

        $this->confirmingMemberRemoval = false;

        $this->memberIdBeingRemoved = null;

        $this->account->refresh();
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
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('portal.account-member-manager');
    }
}

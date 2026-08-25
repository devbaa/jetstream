<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Portal;

use Illuminate\Support\Facades\Gate;
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
        $this->authorizeManagingInvitations();

        if ($invitationId !== '') {
            $this->account->customerInvitations()->whereKey($invitationId)->delete();
        }

        $this->account->refresh();
    }

    /**
     * Make sure the current user may manage this account's invitations.
     *
     * Every other mutation on this component hands the acting user to an
     * action that authorizes it. Cancelling wrote to the database directly and
     * checked nothing: the button is hidden in the view, but a Livewire method
     * is a server endpoint, and anyone who can reach the component can call it
     * with an invitation id.
     *
     * The rule is the one invitation creation already uses — the account's
     * owner, or tenant staff who may manage the tenant's customers — because
     * withdrawing an invitation is the same authority as extending it. It is
     * deliberately not the narrower "addMember" the view happens to guard the
     * button with, which would leave the staff who created an invitation
     * unable to take it back.
     *
     * @return void
     */
    protected function authorizeManagingInvitations()
    {
        $gate = Gate::forUser($this->user);

        if ($gate->check('addMember', $this->account)) {
            return;
        }

        abort_unless($gate->check('manageCustomers', $this->account->tenant()->firstOrFail()), 403);
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

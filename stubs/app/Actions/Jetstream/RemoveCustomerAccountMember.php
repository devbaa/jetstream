<?php

namespace App\Actions\Jetstream;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesCustomerAccountMembers;

class RemoveCustomerAccountMember implements RemovesCustomerAccountMembers
{
    /**
     * Remove the member from the given customer account.
     */
    public function remove(User $user, CustomerAccount $account, User $member): void
    {
        $this->authorize($user, $account, $member);

        $this->ensureUserDoesNotOwnAccount($member, $account);

        $account->removeUser($member);
    }

    /**
     * Authorize that the user can remove the member.
     */
    protected function authorize(User $user, CustomerAccount $account, User $member): void
    {
        if (! Gate::forUser($user)->check('removeMember', $account) &&
            $user->id !== $member->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ensure that the member does not own the customer account.
     */
    protected function ensureUserDoesNotOwnAccount(User $member, CustomerAccount $account): void
    {
        if ($member->id === $account->owner->id) {
            throw ValidationException::withMessages([
                'account' => [__('You may not leave a customer account that you own.')],
            ])->errorBag('removeAccountMember');
        }
    }
}

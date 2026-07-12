<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Contracts\CreatesCustomerAccounts;
use Laravel\Jetstream\Events\CustomerInvitationAccepted;
use Laravel\Jetstream\Jetstream;

class CustomerInvitationController extends Controller
{
    /**
     * Accept a customer invitation.
     *
     * Invitations tied to a customer account add the invitee as a member of
     * that account. Invitations without one create a fresh customer account
     * owned by the invitee.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $invitationId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function accept(Request $request, $invitationId)
    {
        $model = Jetstream::customerInvitationModel();

        $invitation = (new $model)->newQuery()->withoutTenancy()->whereKey($invitationId)->firstOrFail();

        $user = Jetstream::findUserByEmailOrFail($invitation->email);

        if ($invitation->customer_account_id !== null) {
            $account = $invitation->customerAccount()->withoutTenancy()->firstOrFail();

            $account->users()->attach($user);
        } else {
            $account = app(CreatesCustomerAccounts::class)->create(
                $invitation->tenant()->firstOrFail(), $user, ['name' => $user->name]
            );
        }

        $user->switchCustomerAccount($account);

        $invitation->delete();

        CustomerInvitationAccepted::dispatch($account, $user);

        return redirect()->route('portal.show')->banner(
            __('Great! You have accepted the invitation to become a customer of :tenant.', ['tenant' => $account->tenant()->firstOrFail()->name]),
        );
    }

    /**
     * Cancel the given customer invitation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $invitationId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, $invitationId)
    {
        $model = Jetstream::customerInvitationModel();

        $invitation = (new $model)->newQuery()->withoutTenancy()->whereKey($invitationId)->firstOrFail();

        $user = Jetstream::currentUser();

        $account = $invitation->customer_account_id !== null
                        ? $invitation->customerAccount()->withoutTenancy()->first()
                        : null;

        if (! ($account !== null && $user->ownsCustomerAccount($account)) &&
            ! Gate::forUser($user)->check('manageCustomers', $invitation->tenant()->first())) {
            throw new AuthorizationException;
        }

        $invitation->delete();

        return back(303);
    }
}

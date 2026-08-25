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
     * Accepting is several writes — join or create an account, switch the
     * invitee to it, consume the invitation — so the invitation is taken as a
     * lock and the writes are one transaction. Two requests carrying the same
     * signed link would otherwise both read a row that is still there and both
     * act on it, and because the account is created before the invitation is
     * deleted, the one that lost would already have made an account nobody
     * asked for. Holding the row makes the invitation the thing they contend
     * for: the second request waits, then finds it consumed and gets the 404 a
     * spent invitation has always given.
     *
     * The transaction runs on the invitation's own connection. Where the
     * invitation, the customer account and the user live on one connection —
     * which is the case for a stock installation — that covers every write
     * here. An application that splits them across connections keeps the lock
     * and the deletion atomic but not the account and membership writes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $invitationId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function accept(Request $request, $invitationId)
    {
        $model = Jetstream::customerInvitationModel();

        $accepted = (new $model)->getConnection()->transaction(function () use ($model, $invitationId) {
            $invitation = (new $model)->newQuery()
                ->withoutTenancy()
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->firstOrFail();

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

            return [$account, $user];
        });

        [$account, $user] = $accepted;

        // After the commit: the event says an invitation was accepted, and
        // nothing may hear that until it is true. Dispatched from inside, a
        // listener would read an account a later failure takes away again.
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

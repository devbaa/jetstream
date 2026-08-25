<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Events\InvitingCustomer;
use Laravel\Jetstream\Mail\CustomerInvitation;

class InviteCustomer implements InvitesCustomers
{
    /**
     * Invite a new customer to the given tenant.
     *
     * When an account is given, the invitee joins that customer account as a
     * member. Otherwise, accepting the invitation creates a fresh customer
     * account owned by the invitee.
     */
    public function invite(User $user, Tenant $tenant, string $email, ?CustomerAccount $account = null): void
    {
        $this->authorize($user, $tenant, $account);

        $this->validate($tenant, $email, $account);

        InvitingCustomer::dispatch($tenant, $email, $account);

        try {
            $invitation = $tenant->customerInvitations()->create([
                'email' => $email,
                'customer_account_id' => $account?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The check above cannot decide a race: another request may insert
            // the same invitation between it and this write. The table refuses
            // the duplicate, and losing that way means exactly what the check
            // means, so it is reported the same way.
            throw ValidationException::withMessages([
                'email' => __('This email address has already been invited.'),
            ])->errorBag('inviteCustomer');
        }

        Mail::to($email)->send(new CustomerInvitation($invitation));
    }

    /**
     * Authorize the invitation.
     *
     * Tenant staff may invite new customers; customer account owners may
     * invite members into their own account.
     */
    protected function authorize(User $user, Tenant $tenant, ?CustomerAccount $account): void
    {
        if ($account && $user->ownsCustomerAccount($account)) {
            return;
        }

        if (! Gate::forUser($user)->check('manageCustomers', $tenant)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Validate the invite customer operation.
     */
    protected function validate(Tenant $tenant, string $email, ?CustomerAccount $account): void
    {
        Validator::make([
            'email' => $email,
        ], [
            'email' => ['required', 'email'],
        ])->after(function ($validator) use ($tenant, $email, $account) {
            $validator->errors()->addIf(
                $tenant->customerInvitations()
                    ->where('email', $email)
                    ->where('customer_account_id', $account?->id)
                    ->exists(),
                'email',
                __('This email address has already been invited.')
            );

            if ($account) {
                $validator->errors()->addIf(
                    $account->hasUserWithEmail($email),
                    'email',
                    __('This user already belongs to the customer account.')
                );
            }
        })->validateWithBag('inviteCustomer');
    }
}

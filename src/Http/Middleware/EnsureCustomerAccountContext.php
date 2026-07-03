<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Jetstream\HasCustomerAccounts;
use Laravel\Jetstream\Tenancy\CustomerContext;
use Laravel\Jetstream\Tenancy\TenantContext;

class EnsureCustomerAccountContext
{
    /**
     * Resolve the authenticated user's current customer account into context.
     *
     * The tenant context is derived from the account, so tenant scoped models
     * resolve correctly within the customer portal as well.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless($user && array_key_exists(
            HasCustomerAccounts::class, class_uses_recursive($user)
        ), 403);

        $account = $user->currentCustomerAccount;

        if ($account && ! $user->belongsToCustomerAccount($account)) {
            $user->forceFill(['current_customer_account_id' => null])->save();

            $user->setRelation('currentCustomerAccount', null);

            $account = null;
        }

        if (! $account && ($only = $this->onlyAccountOf($user))) {
            $user->switchCustomerAccount($only);

            $account = $only;
        }

        if (! $account && ! $request->routeIs('portal.show')) {
            return $user->allCustomerAccounts()->isEmpty()
                        ? abort(403)
                        : redirect()->route('portal.show');
        }

        app(CustomerContext::class)->set($account);
        app(TenantContext::class)->set($account?->tenant()->first());

        return $next($request);
    }

    /**
     * Get the user's only customer account, if they have exactly one.
     *
     * @param  mixed  $user
     * @return mixed
     */
    protected function onlyAccountOf($user)
    {
        $accounts = $user->allCustomerAccounts();

        return $accounts->count() === 1 ? $accounts->first() : null;
    }
}

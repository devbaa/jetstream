<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Jetstream\Jetstream;

class CurrentCustomerAccountController extends Controller
{
    /**
     * Update the authenticated user's current customer account.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $account = Jetstream::newCustomerAccountModel()
            ->newQuery()
            ->withoutTenancy()
            ->findOrFail($request->customer_account_id);

        if (! $request->user()->switchCustomerAccount($account)) {
            abort(403);
        }

        return redirect()->route('portal.show', [], 303);
    }
}

<?php

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Jetstream\Tenancy\CustomerContext;

class PortalController extends Controller
{
    /**
     * Show the customer portal dashboard.
     *
     * When the user has more than one customer account and none is current,
     * this screen doubles as the account picker.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function show(Request $request)
    {
        return view('portal.show', [
            'user' => $request->user(),
            'account' => app(CustomerContext::class)->current(),
        ]);
    }

    /**
     * Show the customer account settings screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function account(Request $request)
    {
        $account = app(CustomerContext::class)->current();

        abort_unless($account, 403);

        return view('portal.account', [
            'user' => $request->user(),
            'account' => $account,
        ]);
    }
}

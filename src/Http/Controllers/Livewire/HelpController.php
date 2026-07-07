<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HelpController extends Controller
{
    /**
     * Show the account help center: recovery, passkeys, privacy, deletion.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function account(Request $request)
    {
        return view('help.account', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Show the tenant help center: roles, teams, staff, freezing, blocking.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function tenant(Request $request)
    {
        return view('help.tenant', [
            'user' => $request->user(),
        ]);
    }
}

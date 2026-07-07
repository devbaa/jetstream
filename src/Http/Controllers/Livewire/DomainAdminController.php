<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DomainAdminController extends Controller
{
    /**
     * Show the user's domain administration screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function show(Request $request)
    {
        return view('domains.show', [
            'user' => $request->user(),
        ]);
    }
}

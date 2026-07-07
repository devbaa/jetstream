<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminAuditController extends Controller
{
    /**
     * Show the system administrator's application-wide audit log.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('admin.audit', [
            'user' => $request->user(),
        ]);
    }
}

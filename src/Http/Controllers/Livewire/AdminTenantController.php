<?php

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminTenantController extends Controller
{
    /**
     * Show the system administrator's tenant management screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('admin.tenants', [
            'user' => $request->user(),
        ]);
    }
}

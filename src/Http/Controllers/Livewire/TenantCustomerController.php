<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;

class TenantCustomerController extends Controller
{
    /**
     * Show the tenant's customer management screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $tenantId
     * @return \Illuminate\View\View
     */
    public function index(Request $request, $tenantId)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($tenantId);

        if (Gate::denies('manageCustomers', $tenant)) {
            abort(403);
        }

        return view('customers.index', [
            'user' => $request->user(),
            'tenant' => $tenant,
        ]);
    }
}

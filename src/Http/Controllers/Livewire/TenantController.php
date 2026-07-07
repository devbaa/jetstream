<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;

class TenantController extends Controller
{
    /**
     * Show the tenant management screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $tenantId
     * @return \Illuminate\View\View
     */
    public function show(Request $request, $tenantId)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($tenantId);

        if (Gate::denies('view', $tenant)) {
            abort(403);
        }

        return view('tenants.show', [
            'user' => $request->user(),
            'tenant' => $tenant,
        ]);
    }

    /**
     * Show the tenant creation screen.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        Gate::authorize('create', Jetstream::newTenantModel());

        return view('tenants.create', [
            'user' => $request->user(),
        ]);
    }
}

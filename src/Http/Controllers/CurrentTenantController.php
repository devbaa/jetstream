<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Jetstream\Jetstream;

class CurrentTenantController extends Controller
{
    /**
     * Update the authenticated user's current tenant.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($request->tenant_id);

        if (! $request->user()->switchTenant($tenant)) {
            abort(403);
        }

        return redirect(config('fortify.home'), 303);
    }
}

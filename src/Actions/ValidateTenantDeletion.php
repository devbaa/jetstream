<?php

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;

class ValidateTenantDeletion
{
    /**
     * Validate that the tenant can be deleted by the given user.
     *
     * @param  mixed  $user
     * @param  mixed  $tenant
     * @return void
     */
    public function validate($user, $tenant)
    {
        Gate::forUser($user)->authorize('delete', $tenant);
    }
}

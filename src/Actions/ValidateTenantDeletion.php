<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;

class ValidateTenantDeletion
{
    /**
     * Validate that the tenant can be deleted by the given user.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @return void
     */
    public function validate($user, $tenant)
    {
        Gate::forUser($user)->authorize('delete', $tenant);
    }
}

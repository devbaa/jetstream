<?php

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TenantStaffUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class UpdateTenantStaffRole
{
    /**
     * Update the role for the given tenant staff member.
     *
     * @param  mixed  $user
     * @param  mixed  $tenant
     * @param  int  $staffMemberId
     * @param  string  $role
     * @return void
     */
    public function update($user, $tenant, $staffMemberId, string $role)
    {
        Gate::forUser($user)->authorize('updateTenantStaff', $tenant);

        Validator::make([
            'role' => $role,
        ], [
            'role' => ['required', 'string', new Role],
        ])->validate();

        $tenant->users()->updateExistingPivot($staffMemberId, [
            'role' => $role,
        ]);

        TenantStaffUpdated::dispatch($tenant->fresh(), Jetstream::findUserByIdOrFail($staffMemberId));
    }
}

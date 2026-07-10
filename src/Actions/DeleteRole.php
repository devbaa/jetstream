<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;

class DeleteRole
{
    /**
     * Delete the given tenant role.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  \Laravel\Jetstream\DatabaseRole  $role
     * @return void
     */
    public function delete($user, $tenant, $role)
    {
        Gate::forUser($user)->authorize('manageRoles', $tenant);

        abort_unless($role->tenant_id === $tenant->id, 403);

        if ($this->roleIsAssigned($tenant, $role)) {
            throw ValidationException::withMessages([
                'role' => [__('This role is still assigned to one or more members.')],
            ])->errorBag('deleteRole');
        }

        $role->delete();
    }

    /**
     * Determine if the role's key is still assigned within the tenant.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  \Laravel\Jetstream\DatabaseRole  $role
     * @return bool
     */
    protected function roleIsAssigned($tenant, $role)
    {
        if ($tenant->users()->wherePivot('role', $role->key)->exists()) {
            return true;
        }

        $membershipModel = Jetstream::membershipModel();

        return (new $membershipModel)->newQuery()
            ->where('role', $role->key)
            ->whereIn('team_id', $tenant->teams()->select('id'))
            ->exists();
    }
}

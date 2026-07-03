<?php

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;

class DeleteRole
{
    /**
     * Delete the given tenant role.
     *
     * @param  mixed  $user
     * @param  mixed  $tenant
     * @param  mixed  $role
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

        app(RoleRegistry::class)->flush();
    }

    /**
     * Determine if the role's key is still assigned within the tenant.
     *
     * @param  mixed  $tenant
     * @param  mixed  $role
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

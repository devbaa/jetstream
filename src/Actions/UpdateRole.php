<?php

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;

class UpdateRole
{
    /**
     * Update the given tenant role.
     *
     * The role's key is immutable so existing membership assignments never dangle.
     *
     * @param  mixed  $user
     * @param  mixed  $tenant
     * @param  mixed  $role
     * @param  array  $input
     * @return void
     */
    public function update($user, $tenant, $role, array $input)
    {
        Gate::forUser($user)->authorize('manageRoles', $tenant);

        abort_unless($role->tenant_id === $tenant->id, 403);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ])->validateWithBag('updateRole');

        $role->forceFill([
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'permissions' => Jetstream::validPermissions($input['permissions']),
        ])->save();

        app(RoleRegistry::class)->flush();
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;

class UpdateRole
{
    /**
     * Update the given tenant role.
     *
     * The role's key is immutable so existing membership assignments never dangle.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  \Laravel\Jetstream\DatabaseRole  $role
     * @param  array<string, mixed>  $input
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

        $permissions = Jetstream::validPermissions(self::stringList($input['permissions']));

        if ($permissions === []) {
            throw ValidationException::withMessages([
                'permissions' => [__('At least one valid permission must be selected.')],
            ])->errorBag('updateRole');
        }

        $role->forceFill([
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'permissions' => $permissions,
        ])->save();

        app(RoleRegistry::class)->flush();
    }

    /**
     * Normalize untrusted input into a list of strings.
     *
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}

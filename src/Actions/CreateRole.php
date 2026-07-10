<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;

class CreateRole
{
    /**
     * Create a new role for the given tenant.
     *
     * A tenant role may share its key with an application default role, in
     * which case it overrides that default within the tenant.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  array<string, mixed>  $input
     * @return \Laravel\Jetstream\DatabaseRole
     */
    public function create($user, $tenant, array $input)
    {
        Gate::forUser($user)->authorize('manageRoles', $tenant);

        Validator::make($input, [
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'not_in:owner'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ], [
            'key.regex' => __('The key may only contain lowercase letters, numbers, and dashes.'),
            'key.not_in' => __('The owner role is reserved.'),
        ])->validateWithBag('createRole');

        if ($tenant->roles()->where('key', $input['key'])->exists()) {
            throw ValidationException::withMessages([
                'key' => [__('This role key is already in use.')],
            ])->errorBag('createRole');
        }

        $permissions = Jetstream::validPermissions(self::stringList($input['permissions']));

        if ($permissions === []) {
            throw ValidationException::withMessages([
                'permissions' => [__('At least one valid permission must be selected.')],
            ])->errorBag('createRole');
        }

        $role = $tenant->roles()->create([
            'key' => $input['key'],
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'permissions' => $permissions,
        ]);

        app(RoleRegistry::class)->flush();

        return $role;
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

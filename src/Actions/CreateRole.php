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
     * @param  mixed  $user
     * @param  mixed  $tenant
     * @param  array  $input
     * @return mixed
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

        $role = $tenant->roles()->create([
            'key' => $input['key'],
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'permissions' => Jetstream::validPermissions($input['permissions']),
        ]);

        app(RoleRegistry::class)->flush();

        return $role;
    }
}

<?php

namespace App\Actions\Jetstream;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Jetstream\Contracts\CreatesTenants;
use Laravel\Jetstream\Events\AddingTenant;
use Laravel\Jetstream\Jetstream;

class CreateTenant implements CreatesTenants
{
    /**
     * Validate and create a new tenant for the given user.
     *
     * @param  array<string, string>  $input
     */
    public function create(User $user, array $input): Tenant
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTenantModel());

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('createTenant');

        AddingTenant::dispatch($user);

        $user->switchTenant($tenant = $user->ownedTenants()->create([
            'name' => $input['name'],
            'slug' => $this->generateSlug($input['name']),
        ]));

        return $tenant;
    }

    /**
     * Generate a unique slug for the tenant.
     */
    protected function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';

        $slug = $base;

        $suffix = 2;

        while (Jetstream::newTenantModel()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}

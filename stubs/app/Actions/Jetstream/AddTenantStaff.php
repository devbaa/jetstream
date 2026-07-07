<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\AddsTenantStaff;
use Laravel\Jetstream\Events\TenantStaffAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class AddTenantStaff implements AddsTenantStaff
{
    /**
     * Add a new staff member to the given tenant.
     */
    public function add(User $user, Tenant $tenant, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTenantStaff', $tenant);

        $this->validate($tenant, $email, $role);

        $newStaffMember = Jetstream::findUserByEmailOrFail($email);

        $tenant->users()->attach(
            $newStaffMember, ['role' => $role]
        );

        TenantStaffAdded::dispatch($tenant, $newStaffMember);
    }

    /**
     * Validate the add staff member operation.
     */
    protected function validate(Tenant $tenant, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules(), [
            'email.exists' => __('We were unable to find a registered user with this email address.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTenant($tenant, $email)
        )->validateWithBag('addTenantStaff');
    }

    /**
     * Get the validation rules for adding a staff member.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    protected function rules(): array
    {
        return array_filter([
            'email' => ['required', 'email', 'exists:users'],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already a staff member of the tenant.
     */
    protected function ensureUserIsNotAlreadyOnTenant(Tenant $tenant, string $email): Closure
    {
        return function ($validator) use ($tenant, $email) {
            $validator->errors()->addIf(
                $tenant->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the organization.')
            );
        };
    }
}

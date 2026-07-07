<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesTenantStaff;
use Laravel\Jetstream\Events\TenantStaffRemoved;

class RemoveTenantStaff implements RemovesTenantStaff
{
    /**
     * Remove the staff member from the given tenant.
     */
    public function remove(User $user, Tenant $tenant, User $staffMember): void
    {
        $this->authorize($user, $tenant, $staffMember);

        $this->ensureUserDoesNotOwnTenant($staffMember, $tenant);

        $tenant->removeUser($staffMember);

        TenantStaffRemoved::dispatch($tenant, $staffMember);
    }

    /**
     * Authorize that the user can remove the staff member.
     */
    protected function authorize(User $user, Tenant $tenant, User $staffMember): void
    {
        if (! Gate::forUser($user)->check('removeTenantStaff', $tenant) &&
            $user->id !== $staffMember->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ensure that the currently authenticated user does not own the tenant.
     */
    protected function ensureUserDoesNotOwnTenant(User $staffMember, Tenant $tenant): void
    {
        if ($staffMember->id === $tenant->owner->id) {
            throw ValidationException::withMessages([
                'tenant' => [__('You may not leave an organization that you created.')],
            ])->errorBag('removeTenantStaff');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TenantPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return config('jetstream.tenants.self_service_creation', true) || $user->isSystemAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'tenant:update');
    }

    /**
     * Determine whether the user can add staff members to the tenant.
     */
    public function addTenantStaff(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    /**
     * Determine whether the user can update staff member roles.
     */
    public function updateTenantStaff(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    /**
     * Determine whether the user can remove staff members from the tenant.
     */
    public function removeTenantStaff(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    /**
     * Determine whether the user can manage the tenant's roles.
     */
    public function manageRoles(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'roles:manage');
    }

    /**
     * Determine whether the user can manage the tenant's customers.
     */
    public function manageCustomers(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantPermission($tenant, 'customers:manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->ownsTenant($tenant);
    }
}

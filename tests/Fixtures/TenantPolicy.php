<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class TenantPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Tenant $tenant)
    {
        return $user->belongsToTenant($tenant);
    }

    public function create(User $user)
    {
        return config('jetstream.tenants.self_service_creation', true) || $user->isSystemAdmin();
    }

    public function update(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'tenant:update');
    }

    public function addTenantStaff(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    public function updateTenantStaff(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    public function removeTenantStaff(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'staff:manage');
    }

    public function manageRoles(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'roles:manage');
    }

    public function manageCustomers(User $user, Tenant $tenant)
    {
        return $user->hasTenantPermission($tenant, 'customers:manage');
    }

    public function delete(User $user, Tenant $tenant)
    {
        return $user->ownsTenant($tenant);
    }
}

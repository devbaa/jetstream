<?php

namespace App\Policies;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerAccountPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the model.
     *
     * Tenant staff that manage customers may also view customer accounts.
     */
    public function view(User $user, CustomerAccount $account): bool
    {
        return $user->belongsToCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CustomerAccount $account): bool
    {
        return $user->ownsCustomerAccount($account);
    }

    /**
     * Determine whether the user can add members to the customer account.
     */
    public function addMember(User $user, CustomerAccount $account): bool
    {
        return $user->ownsCustomerAccount($account);
    }

    /**
     * Determine whether the user can remove members from the customer account.
     */
    public function removeMember(User $user, CustomerAccount $account): bool
    {
        return $user->ownsCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CustomerAccount $account): bool
    {
        return $user->ownsCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }
}

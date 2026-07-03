<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\CustomerAccount;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerAccountPolicy
{
    use HandlesAuthorization;

    public function view(User $user, CustomerAccount $account)
    {
        return $user->belongsToCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }

    public function update(User $user, CustomerAccount $account)
    {
        return $user->ownsCustomerAccount($account);
    }

    public function addMember(User $user, CustomerAccount $account)
    {
        return $user->ownsCustomerAccount($account);
    }

    public function removeMember(User $user, CustomerAccount $account)
    {
        return $user->ownsCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }

    public function delete(User $user, CustomerAccount $account)
    {
        return $user->ownsCustomerAccount($account) ||
               $user->hasTenantPermission($account->tenant, 'customers:manage');
    }
}

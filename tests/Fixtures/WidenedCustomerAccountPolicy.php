<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\CustomerAccount;

/**
 * An application that has widened who may add members to an account.
 *
 * Policies are published into the application and belong to it. This one
 * stands in for an application that decided any member may invite, without
 * touching its own InviteCustomer action — a combination the package cannot
 * see and must not depend on.
 */
class WidenedCustomerAccountPolicy extends CustomerAccountPolicy
{
    /** {@inheritdoc} */
    #[\Override]
    public function addMember(User $user, CustomerAccount $account)
    {
        return true;
    }
}

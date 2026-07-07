<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\CustomerAccount;
use Laravel\Jetstream\Contracts\DeletesCustomerAccounts;

class DeleteCustomerAccount implements DeletesCustomerAccounts
{
    /**
     * Soft delete the given customer account.
     *
     * The account is permanently purged by the jetstream:purge command
     * once the configured retention period has elapsed.
     */
    public function delete(CustomerAccount $account): void
    {
        $account->resetCurrentSelections();

        $account->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\CustomerAccount;
use Laravel\Jetstream\Contracts\DeletesCustomerAccounts;

class DeleteCustomerAccount implements DeletesCustomerAccounts
{
    /**
     * Delete the given customer account.
     */
    public function delete(CustomerAccount $account): void
    {
        $account->purge();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\CreatesCustomerAccounts;

class CreateCustomerAccount implements CreatesCustomerAccounts
{
    /**
     * Validate and create a new customer account for the given tenant and owner.
     *
     * @param  array<string, string>  $input
     */
    public function create(Tenant $tenant, User $owner, array $input): CustomerAccount
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('createCustomerAccount');

        return $tenant->customerAccounts()->create([
            'name' => $input['name'],
            'user_id' => $owner->id,
        ]);
    }
}

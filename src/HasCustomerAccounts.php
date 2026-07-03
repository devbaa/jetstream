<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

trait HasCustomerAccounts
{
    /**
     * Determine if the given customer account is the current customer account.
     *
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return bool
     */
    public function isCurrentCustomerAccount($account)
    {
        return $this->currentCustomerAccount &&
               $account->id === $this->currentCustomerAccount->id;
    }

    /**
     * Get the current customer account of the user's context.
     *
     * A user's customer accounts span tenants, so this relation is exempt
     * from tenant scoping.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Laravel\Jetstream\CustomerAccount, $this>
     */
    public function currentCustomerAccount()
    {
        return $this->belongsTo(Jetstream::customerAccountModel(), 'current_customer_account_id')
                        ->withoutTenancy();
    }

    /**
     * Switch the user's customer context to the given customer account.
     *
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return bool
     */
    public function switchCustomerAccount($account)
    {
        if (! $this->hasActiveCustomerAccountAccess($account)) {
            return false;
        }

        $this->forceFill([
            'current_customer_account_id' => $account->id,
        ])->save();

        $this->setRelation('currentCustomerAccount', $account);

        return true;
    }

    /**
     * Determine if the user currently has usable access to the given account.
     *
     * Access requires membership (or ownership), an unfrozen account, and an
     * unfrozen tenant.
     *
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return bool
     */
    public function hasActiveCustomerAccountAccess($account)
    {
        if (! $this->belongsToCustomerAccount($account) || $account === null || $account->isFrozen()) {
            return false;
        }

        $tenant = $account->tenant;

        return $tenant !== null && ! $tenant->isFrozen();
    }

    /**
     * Get all of the customer accounts the user owns or belongs to.
     *
     * @return \Illuminate\Support\Collection<int, \Laravel\Jetstream\CustomerAccount>
     */
    public function allCustomerAccounts()
    {
        return $this->ownedCustomerAccounts->merge($this->customerAccounts)->sortBy('name');
    }

    /**
     * Get all of the customer accounts the user owns.
     *
     * A user's customer accounts span tenants, so this relation is exempt
     * from tenant scoping.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\CustomerAccount, $this>
     */
    public function ownedCustomerAccounts()
    {
        return $this->hasMany(Jetstream::customerAccountModel())
                        ->withoutTenancy();
    }

    /**
     * Get all of the customer accounts the user belongs to.
     *
     * A user's customer accounts span tenants, so this relation is exempt
     * from tenant scoping.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Laravel\Jetstream\CustomerAccount, $this>
     */
    public function customerAccounts()
    {
        return $this->belongsToMany(Jetstream::customerAccountModel(), 'customer_account_user')
                        ->withTimestamps()
                        ->withoutTenancy();
    }

    /**
     * Determine if the user owns the given customer account.
     *
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return bool
     */
    public function ownsCustomerAccount($account)
    {
        if (is_null($account)) {
            return false;
        }

        return $this->id == $account->{$this->getForeignKey()};
    }

    /**
     * Determine if the user belongs to the given customer account.
     *
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return bool
     */
    public function belongsToCustomerAccount($account)
    {
        if (is_null($account)) {
            return false;
        }

        return $this->ownsCustomerAccount($account) || $this->customerAccounts->contains(function ($a) use ($account) {
            return $a->id === $account->id;
        });
    }

    /**
     * Determine if the user is a customer of the given tenant.
     *
     * @param  \Laravel\Jetstream\Tenant|null  $tenant
     * @return bool
     */
    public function isCustomerOf($tenant)
    {
        if (is_null($tenant)) {
            return false;
        }

        return $this->allCustomerAccounts()->contains(function ($account) use ($tenant) {
            return $account->tenant_id === $tenant->id;
        });
    }
}

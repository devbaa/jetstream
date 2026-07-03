<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tenancy;

use Illuminate\Database\Eloquent\Model;

class CustomerContext
{
    /**
     * The customer account that is currently in context.
     *
     * @var \Illuminate\Database\Eloquent\Model|null
     */
    protected $account;

    /**
     * Set the customer account that is currently in context.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $account
     * @return void
     */
    public function set(?Model $account)
    {
        $this->account = $account;
    }

    /**
     * Get the customer account that is currently in context.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function current()
    {
        return $this->account;
    }

    /**
     * Get the primary key of the customer account that is currently in context.
     *
     * @return int|string|null
     */
    public function currentId()
    {
        $key = $this->account?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * Forget the customer account that is currently in context.
     *
     * @return void
     */
    public function forget()
    {
        $this->account = null;
    }
}

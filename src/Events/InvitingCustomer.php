<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;

class InvitingCustomer
{
    use Dispatchable;

    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * The email address of the invitee.
     *
     * @var string
     */
    public $email;

    /**
     * The customer account being invited to, if any.
     *
     * @var \Laravel\Jetstream\CustomerAccount|null
     */
    public $account;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  string  $email
     * @param  \Laravel\Jetstream\CustomerAccount|null  $account
     * @return void
     */
    public function __construct($tenant, $email, $account = null)
    {
        $this->tenant = $tenant;
        $this->email = $email;
        $this->account = $account;
    }
}

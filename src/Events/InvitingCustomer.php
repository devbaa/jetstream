<?php

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;

class InvitingCustomer
{
    use Dispatchable;

    /**
     * The tenant instance.
     *
     * @var \App\Models\Tenant
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
     * @var \App\Models\CustomerAccount|null
     */
    public $account;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Tenant  $tenant
     * @param  string  $email
     * @param  \App\Models\CustomerAccount|null  $account
     * @return void
     */
    public function __construct($tenant, $email, $account = null)
    {
        $this->tenant = $tenant;
        $this->email = $email;
        $this->account = $account;
    }
}

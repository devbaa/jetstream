<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CustomerInvitationAccepted
{
    use Dispatchable;

    /**
     * The customer account that was joined or created.
     *
     * @var \Laravel\Jetstream\CustomerAccount
     */
    public $account;

    /**
     * The user that accepted the invitation.
     *
     * @var \Illuminate\Foundation\Auth\User
     */
    public $user;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\CustomerAccount  $account
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @return void
     */
    public function __construct($account, $user)
    {
        $this->account = $account;
        $this->user = $user;
    }
}

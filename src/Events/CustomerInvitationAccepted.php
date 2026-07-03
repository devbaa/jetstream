<?php

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CustomerInvitationAccepted
{
    use Dispatchable;

    /**
     * The customer account that was joined or created.
     *
     * @var \App\Models\CustomerAccount
     */
    public $account;

    /**
     * The user that accepted the invitation.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\CustomerAccount  $account
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($account, $user)
    {
        $this->account = $account;
        $this->user = $user;
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announced once the acceptance is durable, never before.
 *
 * The controller dispatches this after its own transaction returns, which is
 * only the end of the story when nothing else had a transaction open. Laravel
 * transactions nest: run inside an outer one — transaction middleware, a
 * larger workflow, a job that wraps its work — and the controller's is a
 * savepoint whose commit settles nothing. A listener would then read an
 * account no other connection can see, and an outer rollback could take the
 * whole acceptance away after it had already been announced.
 *
 * Implementing this hands the decision to the transaction manager instead of
 * to the caller: with no transaction open the event dispatches immediately;
 * with one open it is held until the outermost commit and dropped if that
 * rolls back.
 */
class CustomerInvitationAccepted implements ShouldDispatchAfterCommit
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

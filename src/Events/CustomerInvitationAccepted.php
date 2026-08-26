<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announced once the acceptance is durable, never before.
 *
 * Laravel transactions nest. Raised inside an outer one — transaction
 * middleware, a larger workflow, a job that wraps its work — the acceptance's
 * own transaction is a savepoint whose commit settles nothing: a listener
 * would read an account no other connection can see, and an outer rollback
 * could take the whole acceptance away after it had already been announced.
 *
 * Implementing this hands the decision to the transaction manager instead of
 * to the caller. With nothing open it dispatches at once; otherwise it is
 * carried by a transaction and executed when that transaction's connection
 * commits at the outermost level, or discarded if it rolls back.
 *
 * Which transaction carries it is decided when the event is raised, and the
 * manager is never told which connection the event is about: it attaches the
 * callback to whichever transaction was begun most recently, across every
 * connection. Raise this while its own transaction is the newest — from
 * inside it — or it will be carried by something unrelated, and that
 * connection's commit will announce an acceptance that is not durable.
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

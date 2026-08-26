<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announced once the block is durable, never before.
 *
 * The action raises this from inside the transaction that writes the block and
 * revokes what the user held, so its listeners must not run until that
 * transaction commits — otherwise a listener reads a user no other connection
 * sees as blocked, and a rollback can take the block away after it has already
 * been announced.
 */
class UserBlocked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\User  $user
     */
    public function __construct(public $user)
    {
    }
}

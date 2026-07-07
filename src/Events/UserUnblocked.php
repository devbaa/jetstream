<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UserUnblocked
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

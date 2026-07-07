<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class RoleEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The database role instance.
     *
     * @var \Laravel\Jetstream\DatabaseRole
     */
    public $role;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\DatabaseRole  $role
     * @return void
     */
    public function __construct($role)
    {
        $this->role = $role;
    }
}

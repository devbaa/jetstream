<?php

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
     * @var \App\Models\Role
     */
    public $role;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Role  $role
     * @return void
     */
    public function __construct($role)
    {
        $this->role = $role;
    }
}

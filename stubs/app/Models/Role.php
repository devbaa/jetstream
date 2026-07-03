<?php

namespace App\Models;

use Laravel\Jetstream\DatabaseRole;
use Laravel\Jetstream\Events\RoleCreated;
use Laravel\Jetstream\Events\RoleDeleted;
use Laravel\Jetstream\Events\RoleUpdated;

class Role extends DatabaseRole
{
    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => RoleCreated::class,
        'updated' => RoleUpdated::class,
        'deleted' => RoleDeleted::class,
    ];
}

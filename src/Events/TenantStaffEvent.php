<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class TenantStaffEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * The staff member instance.
     *
     * @var \Illuminate\Foundation\Auth\User
     */
    public $user;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @return void
     */
    public function __construct($tenant, $user)
    {
        $this->tenant = $tenant;
        $this->user = $user;
    }
}

<?php

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
     * @var \App\Models\Tenant
     */
    public $tenant;

    /**
     * The staff member instance.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Tenant  $tenant
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($tenant, $user)
    {
        $this->tenant = $tenant;
        $this->user = $user;
    }
}

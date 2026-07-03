<?php

namespace Laravel\Jetstream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class TenantEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The tenant instance.
     *
     * @var \App\Models\Tenant
     */
    public $tenant;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Tenant  $tenant
     * @return void
     */
    public function __construct($tenant)
    {
        $this->tenant = $tenant;
    }
}

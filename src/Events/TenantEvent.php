<?php

declare(strict_types=1);

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
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @return void
     */
    public function __construct($tenant)
    {
        $this->tenant = $tenant;
    }
}

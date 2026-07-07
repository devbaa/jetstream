<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class DomainClaimEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The domain claim instance.
     *
     * @var \Laravel\Jetstream\DomainClaim
     */
    public $claim;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Jetstream\DomainClaim  $claim
     * @return void
     */
    public function __construct($claim)
    {
        $this->claim = $claim;
    }
}

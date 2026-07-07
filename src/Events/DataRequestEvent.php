<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Laravel\Jetstream\DataRequest;

abstract class DataRequestEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public DataRequest $dataRequest)
    {
    }
}

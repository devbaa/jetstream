<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Jetstream\DataRequest as JetstreamDataRequest;
use Laravel\Jetstream\Events\DataRequestCreated;

class DataRequest extends JetstreamDataRequest
{
    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => DataRequestCreated::class,
    ];
}

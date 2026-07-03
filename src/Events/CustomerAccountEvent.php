<?php

namespace Laravel\Jetstream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class CustomerAccountEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The customer account instance.
     *
     * @var \App\Models\CustomerAccount
     */
    public $account;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\CustomerAccount  $account
     * @return void
     */
    public function __construct($account)
    {
        $this->account = $account;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Jetstream\CustomerAccount as JetstreamCustomerAccount;
use Laravel\Jetstream\Events\CustomerAccountCreated;
use Laravel\Jetstream\Events\CustomerAccountDeleted;

class CustomerAccount extends JetstreamCustomerAccount
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'user_id',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => CustomerAccountCreated::class,
        'deleted' => CustomerAccountDeleted::class,
    ];
}

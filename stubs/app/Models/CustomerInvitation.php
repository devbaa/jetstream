<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Jetstream\CustomerInvitation as JetstreamCustomerInvitation;

class CustomerInvitation extends JetstreamCustomerInvitation
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'customer_account_id',
    ];
}

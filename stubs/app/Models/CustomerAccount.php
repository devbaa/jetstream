<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Audit\Auditable;
use Laravel\Jetstream\CustomerAccount as JetstreamCustomerAccount;
use Laravel\Jetstream\Events\CustomerAccountCreated;
use Laravel\Jetstream\Events\CustomerAccountDeleted;

class CustomerAccount extends JetstreamCustomerAccount
{
    use Auditable;
    use HasFactory;

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frozen_at' => 'datetime',
        ];
    }
}

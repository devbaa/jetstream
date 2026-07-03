<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Audit\Auditable;
use Laravel\Jetstream\Events\TenantCreated;
use Laravel\Jetstream\Events\TenantDeleted;
use Laravel\Jetstream\Events\TenantUpdated;
use Laravel\Jetstream\Tenant as JetstreamTenant;

class Tenant extends JetstreamTenant
{
    use Auditable;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'allow_customer_registration',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TenantCreated::class,
        'updated' => TenantUpdated::class,
        'deleted' => TenantDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_customer_registration' => 'boolean',
        ];
    }
}

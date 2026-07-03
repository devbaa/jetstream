<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string $event
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 */
abstract class AuditLog extends Model
{
    /**
     * Audit logs are immutable and only carry a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the model that the log entry was recorded for.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function auditable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user that performed the audited action.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get the tenant that the audited action was performed within.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Laravel\Jetstream\Tenant, $this>
     */
    public function tenant()
    {
        return $this->belongsTo(Jetstream::tenantModel(), 'tenant_id');
    }

    /**
     * Scope the query to the log entries recorded for the given tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForTenant(\Illuminate\Database\Eloquent\Builder $query, Tenant $tenant): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }
}

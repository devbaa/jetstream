<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Jetstream\Jetstream;

trait BelongsToTenant
{
    /**
     * Boot the belongs to tenant trait for a model.
     *
     * @return void
     */
    public static function bootBelongsToTenant()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $context = app(TenantContext::class);

            $tenantId = $context->currentId();

            if (is_null($model->tenant_id) &&
                ! $context->shouldBypass() &&
                is_string($tenantId)) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /**
     * Get the tenant that the model belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Laravel\Jetstream\Tenant, $this>
     */
    public function tenant()
    {
        return $this->belongsTo(Jetstream::tenantModel(), 'tenant_id');
    }

    /**
     * Query the model without the tenant scope applied.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}

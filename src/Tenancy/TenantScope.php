<?php

namespace Laravel\Jetstream\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $context = app(TenantContext::class);

        if ($context->shouldBypass() || is_null($context->currentId())) {
            return;
        }

        $builder->where(function ($query) use ($model, $context) {
            $query->where($model->qualifyColumn('tenant_id'), $context->currentId());

            if ($model->tenantOptional ?? false) {
                $query->orWhereNull($model->qualifyColumn('tenant_id'));
            }
        });
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @return void
     */
    public function extend(Builder $builder)
    {
        $builder->macro('withoutTenancy', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}

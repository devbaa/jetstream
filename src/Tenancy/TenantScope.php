<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<\Illuminate\Database\Eloquent\Model>
 */
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

            if (property_exists($model, 'tenantOptional') && $model->tenantOptional === true) {
                $query->orWhereNull($model->qualifyColumn('tenant_id'));
            }
        });
    }

}

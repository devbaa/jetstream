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
     * The scope fails closed. When no tenant is in context the query is not
     * silently left unscoped — which would expose every tenant's rows — but
     * rejected with a MissingTenantContextException. Code that legitimately
     * operates across tenants must say so explicitly through
     * TenantContext::bypass() or the model's withoutTenancy() scope.
     *
     * @return void
     *
     * @throws \Laravel\Jetstream\Tenancy\MissingTenantContextException
     */
    public function apply(Builder $builder, Model $model)
    {
        $context = app(TenantContext::class);

        if ($context->shouldBypass()) {
            return;
        }

        $tenantId = $context->currentId();

        if (is_null($tenantId)) {
            throw MissingTenantContextException::forModel($model);
        }

        $builder->where(function ($query) use ($model, $tenantId) {
            $query->where($model->qualifyColumn('tenant_id'), $tenantId);

            if (property_exists($model, 'tenantOptional') && $model->tenantOptional === true) {
                $query->orWhereNull($model->qualifyColumn('tenant_id'));
            }
        });
    }
}

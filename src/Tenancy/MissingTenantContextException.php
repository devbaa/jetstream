<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tenancy;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when a tenant scoped query is built without an active tenant.
 *
 * Tenant scoping fails closed: rather than silently running unscoped (which
 * would expose every tenant's rows) or silently returning nothing, a missing
 * tenant context is reported as the programming error it is.
 */
class MissingTenantContextException extends RuntimeException
{
    /**
     * Create a new exception for the given tenant scoped model.
     */
    public static function forModel(Model $model): self
    {
        return new self(sprintf(
            'No tenant context is active while querying [%s]. Tenant scoped queries require an active tenant: '
            .'use the tenant context middleware, TenantContext::runFor(), TenantContext::bypass(), or '
            .'withoutTenancy() for an intentional unscoped operation.',
            $model::class
        ));
    }
}

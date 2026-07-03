<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Jetstream\AuditLog;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;

class Auditor
{
    /**
     * Determine if audit logging is enabled for the application.
     */
    public function enabled(): bool
    {
        return config('jetstream.audit.enabled', true) === true;
    }

    /**
     * Record an audit log entry.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        ?int $userId = null,
    ): ?AuditLog {
        if (! $this->enabled()) {
            return null;
        }

        $log = Jetstream::newAuditLogModel();

        $log->forceFill([
            'tenant_id' => $this->tenantId($auditable),
            'user_id' => $userId ?? $this->currentUserId(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => $this->request()?->ip(),
            'user_agent' => $this->request()?->userAgent(),
        ])->save();

        return $log;
    }

    /**
     * Determine the tenant the entry should be attributed to.
     */
    protected function tenantId(?Model $auditable): ?int
    {
        if ($auditable !== null) {
            $tenantId = $auditable->getAttribute('tenant_id');

            if (is_int($tenantId)) {
                return $tenantId;
            }
        }

        $tenantId = app(TenantContext::class)->currentId();

        return is_int($tenantId) ? $tenantId : null;
    }

    /**
     * Get the ID of the currently authenticated user, if any.
     */
    protected function currentUserId(): ?int
    {
        $id = auth()->id();

        return is_int($id) ? $id : null;
    }

    /**
     * Get the current request instance, if one is bound.
     */
    protected function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        return app('request');
    }
}

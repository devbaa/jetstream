<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Audit;

use Laravel\Jetstream\Jetstream;

/**
 * Records a full change log for any Eloquent model.
 *
 * Add this trait to a model to persist an audit log entry — including the
 * acting user, tenant, IP address, and user agent — every time the model
 * is created, updated, deleted, restored, or force deleted.
 */
trait Auditable
{
    /**
     * Boot the auditable trait for a model.
     */
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(Auditor::class)->record(
                'created', $model, [], $model->auditableAttributeValues($model->getAttributes())
            );
        });

        static::updated(function (self $model): void {
            $new = $model->auditableAttributeValues($model->getChanges());

            if ($new === []) {
                return;
            }

            $old = [];

            foreach (array_keys($new) as $key) {
                $old[$key] = $model->getRawOriginal($key);
            }

            app(Auditor::class)->record('updated', $model, $old, $new);
        });

        static::deleted(function (self $model): void {
            $event = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';

            app(Auditor::class)->record($event, $model);
        });

        static::registerModelEvent('restored', function (self $model): void {
            app(Auditor::class)->record('restored', $model);
        });
    }

    /**
     * Get all of the audit log entries recorded for the model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\Laravel\Jetstream\AuditLog, $this>
     */
    public function auditLogs()
    {
        return $this->morphMany(Jetstream::auditLogModel(), 'auditable');
    }

    /**
     * Get the attribute names that should never be recorded in the audit log.
     *
     * Hidden attributes and timestamp columns are always excluded. Override
     * this method to exclude additional attributes.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return [];
    }

    /**
     * Remove the attributes that should not be recorded in the audit log.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributeValues(array $attributes): array
    {
        $excluded = array_merge(
            $this->getHidden(),
            $this->auditExcludedAttributes(),
            ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }
}

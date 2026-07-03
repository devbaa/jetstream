<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $status
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $process_after
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 */
abstract class DataRequest extends Model
{
    /**
     * The data request types.
     */
    public const string TYPE_DELETION = 'deletion';

    public const string TYPE_EXPORT = 'export';

    /**
     * The data request statuses.
     */
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'data_requests';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'process_after' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the user that the request belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Determine if the request is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Determine if the request is due for processing.
     */
    public function isDue(): bool
    {
        return $this->isPending() &&
               ($this->process_after === null || $this->process_after->isPast());
    }

    /**
     * Mark the request as completed.
     */
    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        Events\DataRequestCompleted::dispatch($this);
    }

    /**
     * Mark the request as cancelled.
     */
    public function markCancelled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        Events\DataRequestCancelled::dispatch($this);
    }

    /**
     * Scope the query to pending deletion requests that are due for processing.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDueForDeletion(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('type', self::TYPE_DELETION)
            ->where('status', self::STATUS_PENDING)
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) {
                $query->whereNull('process_after')->orWhere('process_after', '<=', now());
            });
    }
}

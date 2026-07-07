<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single action a domain admin performed under a domain claim.
 *
 * Activity always stays attached to the claim it was recorded under, so
 * when the domain admin flag moves to a newer claim the old tree survives
 * as history until a system administrator purges it.
 *
 * @property string $id
 * @property string $domain_claim_id
 * @property string|null $user_id
 * @property string|null $subject_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property \Illuminate\Support\Carbon|null $created_at
 */
abstract class DomainActivity extends Model
{
    use HasUuids;

    /**
     * Domain activity is immutable and only carries a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'domain_activities';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'action',
        'details',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the claim the activity was recorded under.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Laravel\Jetstream\DomainClaim, $this>
     */
    public function claim()
    {
        return $this->belongsTo(Jetstream::domainClaimModel(), 'domain_claim_id');
    }

    /**
     * Get the domain admin that performed the action.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get the user the action was performed on, if any.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function subject()
    {
        return $this->belongsTo(Jetstream::userModel(), 'subject_id');
    }
}

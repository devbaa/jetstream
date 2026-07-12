<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A claim of administrative authority over an email domain.
 *
 * Any verified user may start a claim for a domain; each claim carries its
 * own globally unique verification token. The claim whose verification
 * succeeded most recently holds the domain admin flag — verifying a claim
 * supersedes every other verified claim for the same domain, whose recorded
 * activity is then kept as a separate, historic tree.
 *
 * @property string $id
 * @property string $user_id
 * @property string $domain
 * @property string $token
 * @property string|null $method
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $superseded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
abstract class DomainClaim extends Model
{
    use HasUuids;

    /**
     * The name under which verification tokens are published, both as the
     * prefix of the DNS TXT record value and as the meta tag name.
     */
    public const string VERIFICATION_NAME = 'jetstream-domain-verification';

    /**
     * The table associated with the model.
     *
     * @var string|null
     */
    protected $table = 'domain_claims';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * Generate a fresh, unique verification token.
     */
    public static function generateToken(): string
    {
        return Str::random(40);
    }

    /**
     * Get the user that started the claim.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get the domain admin activity recorded under this claim.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\DomainActivity, $this>
     */
    public function activities()
    {
        return $this->hasMany(Jetstream::domainActivityModel(), 'domain_claim_id');
    }

    /**
     * Determine if the claim has been verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Determine if the claim currently holds the domain admin flag.
     */
    public function isActive(): bool
    {
        return $this->verified_at !== null && $this->superseded_at === null;
    }

    /**
     * Get the value the claimant must publish as a DNS TXT record.
     */
    public function recordValue(): string
    {
        return static::VERIFICATION_NAME.'='.$this->token;
    }

    /**
     * Get the meta tag the claimant may publish on the domain's home page instead.
     */
    public function metaTag(): string
    {
        return '<meta name="'.static::VERIFICATION_NAME.'" content="'.$this->token.'">';
    }

    /**
     * Record a domain admin activity under this claim.
     *
     * @param  \Illuminate\Foundation\Auth\User|null  $actor
     * @param  \Illuminate\Foundation\Auth\User|null  $subject
     * @param  array<string, mixed>  $details
     * @return \Laravel\Jetstream\DomainActivity
     */
    public function recordActivity($actor, string $action, $subject = null, array $details = [])
    {
        $activity = $this->activities()->make([
            'action' => $action,
            'details' => $details !== [] ? $details : null,
        ]);

        $activity->forceFill([
            'user_id' => $actor?->getKey(),
            'subject_id' => $subject?->getKey(),
            'created_at' => now(),
        ])->save();

        return $activity;
    }

    /**
     * Scope the query to claims currently holding the domain admin flag.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')->whereNull('superseded_at');
    }

    /**
     * Scope the query to superseded (historic) claims.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_at');
    }
}

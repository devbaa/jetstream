<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Audit\Auditable;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Team as JetstreamTeam;
use Laravel\Jetstream\Tenancy\TenantContext;

class Team extends JetstreamTeam
{
    use Auditable;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'tenant_id',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * The "booted" method of the model.
     *
     * Teams created within a tenant context automatically belong to that
     * tenant. Personal teams never belong to a tenant. Note that teams are
     * intentionally not globally scoped by tenant — access is constrained
     * through the user's team relations and policies.
     */
    protected static function booted(): void
    {
        static::creating(function (self $team) {
            if (is_null($team->tenant_id) && ! $team->personal_team) {
                $team->tenant_id = app(TenantContext::class)->currentId();
            }
        });
    }

    /**
     * Get the tenant that the team belongs to, if any.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tenant()
    {
        return $this->belongsTo(Jetstream::tenantModel());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }
}

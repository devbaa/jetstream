<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An authenticatable that is not the published App\Models\User.
 *
 * Jetstream::useUserModel() exists so an application need not use that class,
 * and every other part of this package resolves users through it. This fixture
 * is the configuration that middleware asking "instanceof \App\Models\User"
 * silently does not apply to.
 *
 * @property \Illuminate\Support\Carbon|null $blocked_at
 */
class StandaloneUser extends Authenticatable
{
    use HasUuids;

    /** {@inheritdoc} */
    protected $table = 'users';

    /** {@inheritdoc} */
    protected $guarded = [];

    /** {@inheritdoc} */
    protected $hidden = ['password', 'remember_token'];

    /**
     * {@inheritdoc}
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'blocked_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

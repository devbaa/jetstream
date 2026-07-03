<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\Audit\Auditable;
use Laravel\Jetstream\HasCustomerAccounts;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Jetstream\HasTenants;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $recovery_email
 * @property \Illuminate\Support\Carbon|null $recovery_email_verified_at
 * @property string $password
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $profile_photo_path
 * @property int|null $current_team_id
 * @property int|null $current_tenant_id
 * @property int|null $current_customer_account_id
 * @property bool $is_system_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable implements PasskeyUser
{
    use Auditable;
    use HasApiTokens;
    use HasCustomerAccounts;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use HasTenants;
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'recovery_email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_system_admin' => 'boolean',
        ];
    }
}

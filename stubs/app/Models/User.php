<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\Audit\Auditable;
use Laravel\Jetstream\HasCustomerAccounts;
use Laravel\Jetstream\HasDomainClaims;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Jetstream\HasTenants;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $phone_country
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property string|null $phone_verification_code
 * @property \Illuminate\Support\Carbon|null $phone_verification_expires_at
 * @property string|null $recovery_email
 * @property \Illuminate\Support\Carbon|null $recovery_email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $profile_photo_path
 * @property string|null $current_team_id
 * @property string|null $current_tenant_id
 * @property string|null $current_customer_account_id
 * @property bool $is_system_admin
 * @property \Illuminate\Support\Carbon|null $blocked_at
 * @property string|null $blocked_reason
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable implements PasskeyUser
{
    use Auditable;
    use HasUuids;
    use HasApiTokens;
    use HasCustomerAccounts;
    use HasDomainClaims;

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
        'middle_name',
        'last_name',
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
        'phone_verification_code',
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
            'phone_verified_at' => 'datetime',
            'phone_verification_expires_at' => 'datetime',
            'blocked_at' => 'datetime',
            'password' => 'hashed',
            'is_system_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's full name.
     *
     * The "name" attribute remains the general-purpose display name; this
     * composes it with the optional middle and last names.
     */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->name,
            $this->middle_name,
            $this->last_name,
        ], fn (?string $part): bool => $part !== null && $part !== '')));
    }

    /**
     * Determine if the user is blocked from the entire application.
     */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $slug
 * @property bool $allow_customer_registration
 * @property \Illuminate\Support\Carbon|string|null $frozen_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
abstract class Tenant extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Determine if the tenant is frozen.
     *
     * A frozen tenant's staff and customers lose access to the tenant until
     * a system administrator unfreezes it.
     */
    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * Get the owner of the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function owner()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get all of the tenant's users including its owner.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Foundation\Auth\User>
     */
    public function allUsers()
    {
        return $this->users->merge(array_filter([$this->owner]));
    }

    /**
     * Get all of the users that belong to the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Foundation\Auth\User, $this, \Laravel\Jetstream\TenantMembership, 'membership'>
     */
    public function users()
    {
        return $this->belongsToMany(Jetstream::userModel(), Jetstream::tenantMembershipModel())
                        ->withPivot('role', 'frozen_at')
                        ->withTimestamps()
                        ->as('membership');
    }

    /**
     * Determine if the given user belongs to the tenant.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function hasUser($user)
    {
        return $this->users->contains($user) || $user->ownsTenant($this);
    }

    /**
     * Determine if the given email address belongs to a user on the tenant.
     *
     * @param  string  $email
     * @return bool
     */
    public function hasUserWithEmail(string $email)
    {
        return $this->allUsers()->contains(function ($user) use ($email) {
            return $user->email === $email;
        });
    }

    /**
     * Determine if the given user has the given permission on the tenant.
     *
     * @param  \App\Models\User  $user
     * @param  string  $permission
     * @return bool
     */
    public function userHasPermission($user, $permission)
    {
        return $user->hasTenantPermission($this, $permission);
    }

    /**
     * Get all of the teams that belong to the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\Team, $this>
     */
    public function teams()
    {
        return $this->hasMany(Jetstream::teamModel());
    }

    /**
     * Get all of the roles that are defined for the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\DatabaseRole, $this>
     */
    public function roles()
    {
        return $this->hasMany(Jetstream::roleModel());
    }

    /**
     * Get all of the customer accounts that belong to the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\CustomerAccount, $this>
     */
    public function customerAccounts()
    {
        return $this->hasMany(Jetstream::customerAccountModel());
    }

    /**
     * Get all of the pending customer invitations for the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\CustomerInvitation, $this>
     */
    public function customerInvitations()
    {
        return $this->hasMany(Jetstream::customerInvitationModel());
    }

    /**
     * Remove the given user from the tenant.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function removeUser($user)
    {
        if ($user->current_tenant_id === $this->id) {
            $user->forceFill([
                'current_tenant_id' => null,
            ])->save();
        }

        $this->users()->detach($user);
    }

    /**
     * Clear the tenant from the current tenant selection of its users.
     *
     * @return void
     */
    public function resetCurrentSelections()
    {
        $this->owner()->where('current_tenant_id', $this->id)
                ->update(['current_tenant_id' => null]);

        $this->users()->where('current_tenant_id', $this->id)
                ->update(['current_tenant_id' => null]);
    }

    /**
     * Permanently purge all of the tenant's resources.
     *
     * @return void
     */
    public function purge()
    {
        $this->resetCurrentSelections();

        $this->users()->detach();

        $this->customerAccounts()->withTrashed()->get()->each->purge();

        $this->customerInvitations()->delete();

        $this->roles()->delete();

        $this->teams()->withTrashed()->get()->each->purge();

        $this->forceDelete();
    }
}

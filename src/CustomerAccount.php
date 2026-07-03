<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Tenancy\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $name
 */
abstract class CustomerAccount extends Model
{
    use BelongsToTenant;

    /**
     * Get the owner of the customer account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function owner()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get all of the customer account's users including its owner.
     *
     * @return \Illuminate\Support\Collection
     */
    public function allUsers()
    {
        return $this->users->merge([$this->owner]);
    }

    /**
     * Get all of the users that belong to the customer account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Foundation\Auth\User, $this>
     */
    public function users()
    {
        return $this->belongsToMany(Jetstream::userModel(), 'customer_account_user')
                        ->withTimestamps();
    }

    /**
     * Determine if the given user belongs to the customer account.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function hasUser($user)
    {
        return $this->users->contains($user) || $user->ownsCustomerAccount($this);
    }

    /**
     * Determine if the given email address belongs to a user on the customer account.
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
     * Get all of the pending member invitations for the customer account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\CustomerInvitation, $this>
     */
    public function customerInvitations()
    {
        return $this->hasMany(Jetstream::customerInvitationModel());
    }

    /**
     * Remove the given user from the customer account.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function removeUser($user)
    {
        if ($user->current_customer_account_id === $this->id) {
            $user->forceFill([
                'current_customer_account_id' => null,
            ])->save();
        }

        $this->users()->detach($user);
    }

    /**
     * Purge all of the customer account's resources.
     *
     * @return void
     */
    public function purge()
    {
        $this->owner()->where('current_customer_account_id', $this->id)
                ->update(['current_customer_account_id' => null]);

        $this->users()->where('current_customer_account_id', $this->id)
                ->update(['current_customer_account_id' => null]);

        $this->users()->detach();

        $this->customerInvitations()->delete();

        $this->delete();
    }
}

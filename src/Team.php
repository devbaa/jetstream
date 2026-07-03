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
 * @property bool $personal_team
 * @property string|null $tenant_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
abstract class Team extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Get the owner of the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function owner()
    {
        return $this->belongsTo(Jetstream::userModel(), 'user_id');
    }

    /**
     * Get all of the team's users including its owner.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Foundation\Auth\User>
     */
    public function allUsers()
    {
        return $this->users->merge(array_filter([$this->owner]));
    }

    /**
     * Get all of the users that belong to the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Foundation\Auth\User, $this, \Laravel\Jetstream\Membership, 'membership'>
     */
    public function users()
    {
        return $this->belongsToMany(Jetstream::userModel(), Jetstream::membershipModel())
                        ->withPivot('role')
                        ->withTimestamps()
                        ->as('membership');
    }

    /**
     * Determine if the given user belongs to the team.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function hasUser($user)
    {
        return $this->users->contains($user) || $user->ownsTeam($this);
    }

    /**
     * Determine if the given email address belongs to a user on the team.
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
     * Determine if the given user has the given permission on the team.
     *
     * @param  \App\Models\User  $user
     * @param  string  $permission
     * @return bool
     */
    public function userHasPermission($user, $permission)
    {
        return $user->hasTeamPermission($this, $permission);
    }

    /**
     * Get all of the pending user invitations for the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\TeamInvitation, $this>
     */
    public function teamInvitations()
    {
        return $this->hasMany(Jetstream::teamInvitationModel());
    }

    /**
     * Remove the given user from the team.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function removeUser($user)
    {
        if ($user->current_team_id === $this->id) {
            $user->forceFill([
                'current_team_id' => null,
            ])->save();
        }

        $this->users()->detach($user);
    }

    /**
     * Clear the team from the current team selection of its users.
     *
     * @return void
     */
    public function resetCurrentSelections()
    {
        $this->owner()->where('current_team_id', $this->id)
                ->update(['current_team_id' => null]);

        $this->users()->where('current_team_id', $this->id)
                ->update(['current_team_id' => null]);
    }

    /**
     * Permanently purge all of the team's resources.
     *
     * @return void
     */
    public function purge()
    {
        $this->resetCurrentSelections();

        $this->users()->detach();

        $this->forceDelete();
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\Concerns\WithRateLimiting;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;
use Laravel\Jetstream\RoleRegistry;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class TeamMemberManager extends Component
{
    use WithRateLimiting;

    /**
     * The team instance.
     *
     * @var \Laravel\Jetstream\Team
     */
    public $team;

    /**
     * Indicates if a user's role is currently being managed.
     *
     * @var bool
     */
    public $currentlyManagingRole = false;

    /**
     * The user that is having their role managed.
     *
     * @var \App\Models\User|null
     */
    public $managingRoleFor;

    /**
     * The current role for the user that is having their role managed.
     *
     * @var string|null
     */
    public $currentRole;

    /**
     * Indicates if the application is confirming if a user wishes to leave the current team.
     *
     * @var bool
     */
    public $confirmingLeavingTeam = false;

    /**
     * Indicates if the application is confirming if a team member should be removed.
     *
     * @var bool
     */
    public $confirmingTeamMemberRemoval = false;

    /**
     * The ID of the team member being removed.
     *
     * @var string|null
     */
    public $teamMemberIdBeingRemoved = null;

    /**
     * The "add team member" form state.
     *
     * @var array{email: string, role: string|null}
     */
    public $addTeamMemberForm = [
        'email' => '',
        'role' => null,
    ];

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Team  $team
     * @return void
     */
    public function mount($team)
    {
        $this->team = $team;
    }

    /**
     * Add a new team member to a team.
     *
     * @return void
     */
    public function addTeamMember()
    {
        $this->resetErrorBag();

        $this->rateLimit('team-member-invite', maxAttempts: 20, decaySeconds: 60);

        if (Features::sendsTeamInvitations()) {
            app(InvitesTeamMembers::class)->invite(
                $this->user,
                $this->team,
                $this->addTeamMemberForm['email'],
                $this->addTeamMemberForm['role']
            );
        } else {
            app(AddsTeamMembers::class)->add(
                $this->user,
                $this->team,
                $this->addTeamMemberForm['email'],
                $this->addTeamMemberForm['role']
            );
        }

        $this->addTeamMemberForm = [
            'email' => '',
            'role' => null,
        ];

        $this->team->refresh();

        $this->dispatch('saved');
    }

    /**
     * Cancel a pending team member invitation.
     *
     * @param  string  $invitationId
     * @return void
     */
    public function cancelTeamInvitation($invitationId)
    {
        abort_unless(Gate::forUser($this->user)->check('removeTeamMember', $this->team), 403);

        if (! empty($invitationId)) {
            $model = Jetstream::teamInvitationModel();

            $foreignKey = (new $model)->team()->getForeignKeyName();

            $model::whereKey($invitationId)
                ->where($foreignKey, $this->team->id)
                ->delete();
        }

        $this->team->refresh();
    }

    /**
     * Allow the given user's role to be managed.
     *
     * @param  string  $userId
     * @return void
     */
    public function manageRole($userId)
    {
        $this->currentlyManagingRole = true;
        $this->managingRoleFor = Jetstream::findUserByIdOrFail($userId);
        $this->currentRole = $this->managingRoleFor->teamRole($this->team)?->key;
    }

    /**
     * Save the role for the user being managed.
     *
     * @param  \Laravel\Jetstream\Actions\UpdateTeamMemberRole  $updater
     * @return void
     */
    public function updateRole(UpdateTeamMemberRole $updater)
    {
        abort_if(is_null($this->managingRoleFor), 403);

        $updater->update(
            $this->user,
            $this->team,
            $this->managingRoleFor->id,
            $this->currentRole ?? ''
        );

        $this->team->refresh();

        $this->stopManagingRole();
    }

    /**
     * Stop managing the role of a given user.
     *
     * @return void
     */
    public function stopManagingRole()
    {
        $this->currentlyManagingRole = false;
    }

    /**
     * Remove the currently authenticated user from the team.
     *
     * @param  \Laravel\Jetstream\Contracts\RemovesTeamMembers  $remover
     * @return \Illuminate\Http\RedirectResponse
     */
    public function leaveTeam(RemovesTeamMembers $remover)
    {
        $remover->remove(
            $this->user,
            $this->team,
            $this->user
        );

        $this->confirmingLeavingTeam = false;

        $this->team->refresh();

        return redirect(Jetstream::homePath());
    }

    /**
     * Confirm that the given team member should be removed.
     *
     * @param  string  $userId
     * @return void
     */
    public function confirmTeamMemberRemoval($userId)
    {
        $this->confirmingTeamMemberRemoval = true;

        $this->teamMemberIdBeingRemoved = $userId;
    }

    /**
     * Remove a team member from the team.
     *
     * @param  \Laravel\Jetstream\Contracts\RemovesTeamMembers  $remover
     * @return void
     */
    public function removeTeamMember(RemovesTeamMembers $remover)
    {
        abort_if(is_null($this->teamMemberIdBeingRemoved), 403);

        $remover->remove(
            $this->user,
            $this->team,
            $user = Jetstream::findUserByIdOrFail($this->teamMemberIdBeingRemoved)
        );

        $this->confirmingTeamMemberRemoval = false;

        $this->teamMemberIdBeingRemoved = null;

        $this->team->refresh();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Jetstream::currentUser();
    }

    /**
     * Get the available team member roles.
     *
     * @return list<\Laravel\Jetstream\Role>
     */
    public function getRolesProperty()
    {
        $roles = Features::hasTenantFeatures()
                    ? app(RoleRegistry::class)->all($this->team->tenant_id)
                    : Jetstream::$roles;

        return array_values(collect($roles)->map(function (Role $role): Role {
            $name = __($role->name);
            $description = __($role->description ?? '');

            return (new Role(
                $role->key,
                is_string($name) ? $name : $role->name,
                $role->permissions
            ))->description(is_string($description) ? $description : '');
        })->all());
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('teams.team-member-manager');
    }
}

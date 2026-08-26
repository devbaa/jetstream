<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class UpdateTeamMemberRole
{
    /**
     * Update the role for the given team member.
     *
     * The membership is looked up and held before it is written, rather than
     * left to the UPDATE. updateExistingPivot() builds a statement constrained
     * to the relation and the given key; where no such membership exists it
     * matches nothing, affects nothing and reports no error, so the caller was
     * told the change succeeded and TeamMemberUpdated was announced for a role
     * that does not exist. A user who is a member of some other team looks
     * exactly the same from here as one who is a member of none.
     *
     * Reading it first would only narrow the window, so the row is taken with
     * lockForUpdate() and the write happens in the same transaction: a
     * membership cannot be removed between the two, which is an ordinary thing
     * for a second administrator to be doing.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Team  $team
     * @param  string  $teamMemberId
     * @param  string  $role
     * @return void
     */
    public function update($user, $team, $teamMemberId, string $role)
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        Validator::make([
            'role' => $role,
        ], [
            'role' => ['required', 'string', Role::for($team->tenant_id)],
        ])->validate();

        $team->getConnection()->transaction(function () use ($team, $teamMemberId, $role) {
            $this->ensureTeamMembershipExists($team, $teamMemberId);

            $team->users()->updateExistingPivot($teamMemberId, [
                'role' => $role,
            ]);

            // Raised inside the transaction so that the deferred event is
            // carried by this transaction rather than by whatever else is
            // open; TeamMemberUpdated says when its listeners may run.
            TeamMemberUpdated::dispatch($team->fresh(), Jetstream::findUserByIdOrFail($teamMemberId));
        });
    }

    /**
     * Take the team membership being changed, and refuse if there is none.
     *
     * Reported as a missing record because that is what it is, and because the
     * screen that reaches this already answers a member it cannot find with a
     * 404 — the id is looked up before the modal opens. A membership that has
     * since been revoked is the same kind of absence.
     *
     * @param  \Laravel\Jetstream\Team  $team
     * @param  string  $teamMemberId
     * @return void
     */
    protected function ensureTeamMembershipExists($team, $teamMemberId)
    {
        $membership = $team->users()
            ->newPivotStatementForId($teamMemberId)
            ->lockForUpdate()
            ->first();

        if (is_null($membership)) {
            throw new ModelNotFoundException(
                'The given user is not a member of this team.'
            );
        }
    }
}

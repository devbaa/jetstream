<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TenantStaffUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class UpdateTenantStaffRole
{
    /**
     * Update the role for the given tenant staff member.
     *
     * The membership is established before it is written, rather than left to
     * the write. updateExistingPivot() writes nothing when there is no such
     * membership and reports no error, so the caller was told the change
     * succeeded and TenantStaffUpdated was announced for a role that does not
     * exist. A user who is staff of some other tenant looks exactly the same
     * from here as one who is staff nowhere at all.
     *
     * Its return value cannot stand in for the check either. These relations
     * name a pivot model, so Laravel calls using() for them and the update
     * takes its custom-class path: load the pivot, fill it, report whether
     * anything became dirty. A membership that does not exist and one that
     * already holds the role being set report the same thing.
     *
     * Reading it first would only narrow the window, so the row is taken with
     * lockForUpdate() and the write happens in the same transaction: a
     * membership cannot be removed between the two, which is an ordinary thing
     * for a second administrator to be doing.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  string  $staffMemberId
     * @param  string  $role
     * @return void
     */
    public function update($user, $tenant, $staffMemberId, string $role)
    {
        Gate::forUser($user)->authorize('updateTenantStaff', $tenant);

        Validator::make([
            'role' => $role,
        ], [
            'role' => ['required', 'string', Role::for($tenant)],
        ])->validate();

        $tenant->getConnection()->transaction(function () use ($tenant, $staffMemberId, $role) {
            $this->ensureStaffMembershipExists($tenant, $staffMemberId);

            $tenant->users()->updateExistingPivot($staffMemberId, [
                'role' => $role,
            ]);

            // Raised inside the transaction so that the deferred event is
            // carried by this transaction rather than by whatever else is
            // open; TenantStaffUpdated says when its listeners may run.
            TenantStaffUpdated::dispatch($tenant->refresh(), Jetstream::findUserByIdOrFail($staffMemberId));
        });
    }

    /**
     * Take the staff membership being changed, and refuse if there is none.
     *
     * Reported as a missing record because that is what it is, and because the
     * screen that reaches this already answers a staff member it cannot find
     * with a 404 — the id is looked up before the modal opens. A membership
     * that has since been revoked is the same kind of absence.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @param  string  $staffMemberId
     * @return void
     */
    protected function ensureStaffMembershipExists($tenant, $staffMemberId)
    {
        $membership = $tenant->users()
            ->newPivotStatementForId($staffMemberId)
            ->lockForUpdate()
            ->first();

        if (is_null($membership)) {
            throw new ModelNotFoundException(
                'The given user is not a staff member of this organization.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;

class AddUserToDomainTeams
{
    /**
     * Add the given user to the team of their domain's current master.
     *
     * When a domain has an active claim, every verified user whose email
     * belongs to that domain is added directly to the domain master's
     * personal team. System administrators and the master themselves are
     * never auto-enrolled, and existing memberships are left untouched.
     *
     * @param  \App\Models\User  $user
     */
    public function add($user): void
    {
        if (! Features::hasDomainAdminFeatures() ||
            ! Features::hasTeamFeatures() ||
            ! $user->hasVerifiedEmail() ||
            $user->isSystemAdmin()) {
            return;
        }

        $domain = $user->emailDomain();

        if ($domain === null) {
            return;
        }

        $claim = Jetstream::newDomainClaimModel()->newQuery()
            ->active()
            ->where('domain', $domain)
            ->first();

        if ($claim === null || $claim->user_id === $user->getKey()) {
            return;
        }

        $master = $claim->user;

        if (! $master instanceof \App\Models\User) {
            return;
        }

        $team = $master->personalTeam();

        if ($team === null || $team->hasUser($user)) {
            return;
        }

        $team->users()->attach($user, ['role' => null]);

        TeamMemberAdded::dispatch($team, $user);

        $claim->recordActivity($master, 'member:added-to-team', $user, ['team_id' => $team->id]);
    }

    /**
     * Add every eligible user of the claim's domain to the master's team.
     *
     * @param  \Laravel\Jetstream\DomainClaim  $claim
     */
    public function addAllForClaim($claim): void
    {
        if (! Features::hasTeamFeatures()) {
            return;
        }

        // lower(email) keeps the match case-insensitive on databases (such as
        // PostgreSQL) whose LIKE is case-sensitive; the claim domain is stored
        // lower-cased already.
        $users = Jetstream::newUserModel()->newQuery()
            ->whereKeyNot($claim->user_id)
            ->whereNotNull('email_verified_at')
            ->where('is_system_admin', false)
            ->whereRaw('lower(email) like ?', ['%@'.strtolower($claim->domain)])
            ->get();

        foreach ($users as $user) {
            if ($user instanceof \App\Models\User) {
                $this->add($user);
            }
        }
    }
}

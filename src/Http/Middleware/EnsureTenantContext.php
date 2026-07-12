<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;

class EnsureTenantContext
{
    /**
     * Resolve the authenticated user's current tenant into the tenant context.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user instanceof \App\Models\User && Jetstream::userHasTenantFeatures($user)) {
            $tenant = $user->currentTenant;

            if ($tenant !== null && ! $user->hasActiveTenantAccess($tenant)) {
                $user->forceFill(['current_tenant_id' => null])->save();

                $user->setRelation('currentTenant', null);

                $tenant = null;
            }

            app(TenantContext::class)->set($tenant);

            $this->ensureCurrentTeamIsWithinTenant($user, $tenant);
        }

        return $next($request);
    }

    /**
     * Heal a current team selection that points into another tenant.
     *
     * @param  \App\Models\User  $user
     * @param  \Laravel\Jetstream\Tenant|null  $tenant
     * @return void
     */
    protected function ensureCurrentTeamIsWithinTenant($user, $tenant)
    {
        if (! Jetstream::userHasTeamFeatures($user) || $user->currentTeam === null) {
            return;
        }

        $teamTenantId = $user->currentTeam->tenant_id;

        if (is_null($teamTenantId)) {
            return;
        }

        if ($tenant === null || $teamTenantId !== $tenant->id) {
            $team = $tenant !== null ? $user->allTeams()->first(function ($team) use ($tenant) {
                return $team->tenant_id === $tenant->id;
            }) : null;

            $user->forceFill([
                'current_team_id' => ($team ?? $user->personalTeam())?->id,
            ])->save();

            $user->unsetRelation('currentTeam');
        }
    }
}

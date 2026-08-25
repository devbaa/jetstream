<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\VerifiesDomains;
use Laravel\Jetstream\DomainClaim;
use Laravel\Jetstream\Events\DomainClaimSuperseded;
use Laravel\Jetstream\Events\DomainClaimVerified;
use Laravel\Jetstream\Jetstream;

class VerifyDomainClaim
{
    /**
     * Attempt to verify the given claim and hand it the domain admin flag.
     *
     * The registered domain verifier looks the claim's token up on the
     * domain. On success the claim is activated through activate().
     *
     * Returns false when the token could not be found on the domain.
     */
    public function verify(DomainClaim $claim): bool
    {
        $method = app(VerifiesDomains::class)->verify($claim);

        if ($method === null) {
            return false;
        }

        $this->activate($claim, $method);

        return true;
    }

    /**
     * Hand the claim the domain admin flag.
     *
     * Every other verified claim for the domain is superseded — the most
     * recent successful verification always holds the flag — while the
     * superseded claims' recorded activity remains untouched as a historic
     * tree. The domain's verified users are then enrolled into the new
     * master's team.
     */
    public function activate(DomainClaim $claim, string $method): void
    {
        $superseded = DB::transaction(function () use ($claim, $method) {
            // Every claim for the domain is locked, not just the active ones.
            // Locking the active ones was the same thing as locking nothing
            // whenever the domain had no admin yet, so two people verifying an
            // unclaimed domain at the same moment each found no one to
            // supersede and both took the flag. The claim being activated is
            // itself a row of this domain, so the set is never empty, and two
            // activations racing for one domain always contend over each
            // other's rows.
            $claims = Jetstream::newDomainClaimModel()->newQuery()
                ->where('domain', $claim->domain)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Act on the row that was locked, not on the copy the caller
            // happened to be holding. A claim that has been superseded since
            // that copy was loaded still reads as active in memory, and
            // Eloquent writes only what changed, so clearing superseded_at
            // would be skipped as "already null" and re-verification would
            // quietly fail to take the flag back.
            $locked = $claims->first(fn (DomainClaim $other): bool => $other->is($claim));

            if ($locked instanceof DomainClaim) {
                $claim->setRawAttributes($locked->getAttributes(), true);
            }

            $superseded = $claims->filter(
                fn (DomainClaim $other): bool => ! $other->is($claim) && $other->isActive()
            )->values();

            foreach ($superseded as $previous) {
                $previous->forceFill(['superseded_at' => now()])->save();
            }

            $claim->forceFill([
                'method' => $method,
                'verified_at' => now(),
                'superseded_at' => null,
            ])->save();

            $claim->recordActivity($claim->user, 'domain:verified', null, ['method' => $method]);

            return $superseded;
        });

        foreach ($superseded as $previous) {
            DomainClaimSuperseded::dispatch($previous);
        }

        DomainClaimVerified::dispatch($claim);

        app(AddUserToDomainTeams::class)->addAllForClaim($claim);
    }
}

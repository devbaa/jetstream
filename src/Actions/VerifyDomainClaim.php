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
     * domain. On success every other verified claim for the domain is
     * superseded — the most recent successful verification always holds
     * the flag — while the superseded claims' recorded activity remains
     * untouched as a historic tree.
     *
     * Returns false when the token could not be found on the domain.
     */
    public function verify(DomainClaim $claim): bool
    {
        $method = app(VerifiesDomains::class)->verify($claim);

        if ($method === null) {
            return false;
        }

        $superseded = DB::transaction(function () use ($claim, $method) {
            $superseded = Jetstream::newDomainClaimModel()->newQuery()
                ->where('domain', $claim->domain)
                ->whereKeyNot($claim->getKey())
                ->active()
                ->get();

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

        return true;
    }
}

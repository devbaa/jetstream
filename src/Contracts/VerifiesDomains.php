<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Contracts;

use Laravel\Jetstream\DomainClaim;

interface VerifiesDomains
{
    /**
     * Check whether the claim's verification token is published on the domain.
     *
     * Returns the method the token was found through ("dns" for a TXT
     * record, "meta" for a home page meta tag) or null when the token
     * could not be found.
     */
    public function verify(DomainClaim $claim): ?string;
}

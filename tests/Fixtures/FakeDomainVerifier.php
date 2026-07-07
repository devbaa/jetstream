<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Laravel\Jetstream\Contracts\VerifiesDomains;
use Laravel\Jetstream\DomainClaim;

class FakeDomainVerifier implements VerifiesDomains
{
    /**
     * The method the fake verifier should report, or null to fail.
     */
    public static ?string $result = 'dns';

    /**
     * The claims the verifier was asked to check.
     *
     * @var array<int, \Laravel\Jetstream\DomainClaim>
     */
    public static array $checked = [];

    public function verify(DomainClaim $claim): ?string
    {
        static::$checked[] = $claim;

        return static::$result;
    }
}

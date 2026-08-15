<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Laravel\Jetstream\Domains\VerifyDomainViaDnsOrMeta;

/**
 * The shipped domain verifier with deterministic DNS and recorded options.
 *
 * DNS resolution is answered from canned records so the suite never touches
 * the network, and the client options the verifier builds are captured so the
 * connection pinning can be asserted — the pinning happens below the HTTP
 * fake, which only sees the request URL.
 */
class RecordingDomainVerifier extends VerifyDomainViaDnsOrMeta
{
    /**
     * The client options built for each home page request, in order.
     *
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<array<array-key, mixed>>  $addressRecords
     * @param  list<array<array-key, mixed>>  $txtRecords
     */
    public function __construct(
        protected array $addressRecords = [],
        protected array $txtRecords = [],
    ) {
        //
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function resolveDnsRecords(string $host): array
    {
        return $this->addressRecords;
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function resolveTxtRecords(string $host): array
    {
        return $this->txtRecords;
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function requestOptions(string $host, string $address): array
    {
        return $this->options[] = parent::requestOptions($host, $address);
    }
}

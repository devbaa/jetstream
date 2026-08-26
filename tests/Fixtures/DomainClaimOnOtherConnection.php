<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\DomainClaim as BaseDomainClaim;

/**
 * A domain claim model configured onto a connection other than the default.
 *
 * The counterpart of UserOnOtherConnection, for the other model this package
 * lets an application replace: Jetstream::useDomainClaimModel().
 */
class DomainClaimOnOtherConnection extends BaseDomainClaim
{
    /**
     * The connection this model's state lives on.
     *
     * @var string|null
     */
    protected $connection = 'jetstream_competitor';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The table this model reads and writes.
     *
     * @var string
     */
    protected $table = 'domain_claims';
}

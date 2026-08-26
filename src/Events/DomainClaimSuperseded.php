<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Announced once the supersession is durable, never before.
 *
 * The action raises this from inside the transaction that holds every claim
 * for the domain, so its listeners must not run until that transaction
 * commits — otherwise a listener reads a flag no other connection can see, and
 * a rollback can take it away after it has already been announced.
 *
 * Declared here rather than on the shared base class, which says what a domain
 * claim event carries and not when one may be believed: an event added to that
 * family later should have to choose this, not inherit it.
 */
class DomainClaimSuperseded extends DomainClaimEvent implements ShouldDispatchAfterCommit
{
    //
}

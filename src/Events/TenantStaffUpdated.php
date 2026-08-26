<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Announced once the role change is durable, never before.
 *
 * The action raises this from inside the transaction that holds the
 * membership, so its listeners must not run until that transaction commits —
 * otherwise a listener reads a role no other connection can see, and a
 * rollback can take the change away after it has already been announced.
 *
 * The base class is shared with the events that report a staff member being
 * added or removed, which belong to other actions and are dispatched outside
 * any transaction, so this contract is declared here rather than there.
 */
class TenantStaffUpdated extends TenantStaffEvent implements ShouldDispatchAfterCommit
{
    //
}

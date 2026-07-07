<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Tenant;
use Laravel\Jetstream\Contracts\DeletesTenants;

class DeleteTenant implements DeletesTenants
{
    /**
     * Soft delete the given tenant.
     *
     * The tenant and all of its teams, customer accounts, roles, and
     * invitations are permanently purged by the jetstream:purge command
     * once the configured retention period has elapsed.
     */
    public function delete(Tenant $tenant): void
    {
        $tenant->resetCurrentSelections();

        $tenant->delete();
    }
}

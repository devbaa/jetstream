<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Tenant;
use Laravel\Jetstream\Contracts\DeletesTenants;

class DeleteTenant implements DeletesTenants
{
    /**
     * Delete the given tenant.
     */
    public function delete(Tenant $tenant): void
    {
        $tenant->purge();
    }
}

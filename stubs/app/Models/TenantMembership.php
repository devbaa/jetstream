<?php

namespace App\Models;

use Laravel\Jetstream\TenantMembership as JetstreamTenantMembership;

class TenantMembership extends JetstreamTenantMembership
{
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;

class DefaultRolesSeeder extends Seeder
{
    /**
     * Copy the application's default roles into the roles table.
     *
     * These rows have no tenant and act as the base role set that every
     * tenant may override or extend through the role manager.
     *
     * "No tenant" is stated by the query rather than mass assigned, because
     * tenant_id is deliberately not fillable on the role model — tenancy
     * stamps it, so that no request can choose which tenant a row lands in.
     * Handing it to updateOrCreate therefore either throws or is discarded,
     * depending on whether the application runs Eloquent strictly.
     *
     * The write is wrapped in a bypass for the same reason from the other
     * side: the stamp fires on create, so a seeder invoked while a tenant is
     * current would otherwise write that tenant's own roles under the name of
     * the application's defaults.
     */
    public function run(): void
    {
        app(TenantContext::class)->bypass(function (): void {
            foreach (Jetstream::$roles as $role) {
                Jetstream::newRoleModel()
                    ->newQuery()
                    ->withoutTenancy()
                    ->whereNull('tenant_id')
                    ->updateOrCreate([
                        'key' => $role->key,
                    ], [
                        'name' => $role->name,
                        'description' => (string) $role->description,
                        'permissions' => $role->permissions,
                    ]);
            }
        });
    }
}

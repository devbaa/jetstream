<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Jetstream\Jetstream;

class DefaultRolesSeeder extends Seeder
{
    /**
     * Copy the application's default roles into the roles table.
     *
     * These rows have no tenant and act as the base role set that every
     * tenant may override or extend through the role manager.
     */
    public function run(): void
    {
        foreach (Jetstream::$roles as $role) {
            Jetstream::newRoleModel()
                ->newQuery()
                ->withoutTenancy()
                ->updateOrCreate([
                    'tenant_id' => null,
                    'key' => $role->key,
                ], [
                    'name' => $role->name,
                    'description' => (string) $role->description,
                    'permissions' => $role->permissions,
                ]);
        }
    }
}

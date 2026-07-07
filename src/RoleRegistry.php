<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

class RoleRegistry
{
    /**
     * The resolved roles, memoized per tenant for the current request.
     *
     * @var array<string, array<string, \Laravel\Jetstream\Role>>
     */
    protected $memo = [];

    /**
     * Find the role with the given key for the given tenant.
     *
     * Tenant specific roles take precedence over the application's database
     * defaults, which in turn take precedence over statically registered roles.
     *
     * @param  string  $key
     * @param  int|string|null  $tenantId
     * @return \Laravel\Jetstream\Role|null
     */
    public function find(string $key, $tenantId = null)
    {
        return $this->all($tenantId)[$key] ?? null;
    }

    /**
     * Get all of the roles that are available to the given tenant, keyed by role key.
     *
     * @param  int|string|null  $tenantId
     * @return array<string, \Laravel\Jetstream\Role>
     */
    public function all($tenantId = null)
    {
        $memoKey = (string) ($tenantId ?? '');

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $roles = Jetstream::$roles;

        $this->databaseRoles($tenantId)->each(function ($role) use (&$roles) {
            $roles[$role->key] = $role->toRole();
        });

        return $this->memo[$memoKey] = $roles;
    }

    /**
     * Flush the memoized roles, for example after a role has been written.
     *
     * @return void
     */
    public function flush()
    {
        $this->memo = [];
    }

    /**
     * Get the database roles for the given tenant, defaults first so tenant rows win.
     *
     * @param  int|string|null  $tenantId
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\DatabaseRole>
     */
    protected function databaseRoles($tenantId)
    {
        return Jetstream::newRoleModel()
            ->newQuery()
            ->withoutTenancy()
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');

                if (! is_null($tenantId)) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderByRaw('tenant_id is not null')
            ->get();
    }
}

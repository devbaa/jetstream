<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

trait HasTenants
{
    /**
     * Determine if the given tenant is the current tenant.
     *
     * @param  mixed  $tenant
     * @return bool
     */
    public function isCurrentTenant($tenant)
    {
        return $this->currentTenant && $tenant->id === $this->currentTenant->id;
    }

    /**
     * Get the current tenant of the user's context.
     *
     * Unlike teams, users do not receive a personal tenant, so this may be null.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currentTenant()
    {
        return $this->belongsTo(Jetstream::tenantModel(), 'current_tenant_id');
    }

    /**
     * Switch the user's context to the given tenant.
     *
     * @param  mixed  $tenant
     * @return bool
     */
    public function switchTenant($tenant)
    {
        if (! $this->belongsToTenant($tenant)) {
            return false;
        }

        $attributes = [
            'current_tenant_id' => $tenant->id,
        ];

        if (array_key_exists(HasTeams::class, class_uses_recursive($this))) {
            $team = $this->allTeams()->first(function ($team) use ($tenant) {
                return $team->tenant_id === $tenant->id;
            }) ?? $this->personalTeam();

            $attributes['current_team_id'] = $team?->id;

            $this->setRelation('currentTeam', $team);
        }

        $this->forceFill($attributes)->save();

        $this->setRelation('currentTenant', $tenant);

        return true;
    }

    /**
     * Get all of the tenants the user owns or belongs to.
     *
     * @return \Illuminate\Support\Collection
     */
    public function allTenants()
    {
        return $this->ownedTenants->merge($this->tenants)->sortBy('name');
    }

    /**
     * Get all of the tenants the user owns.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function ownedTenants()
    {
        return $this->hasMany(Jetstream::tenantModel());
    }

    /**
     * Get all of the tenants the user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tenants()
    {
        return $this->belongsToMany(Jetstream::tenantModel(), Jetstream::tenantMembershipModel())
                        ->withPivot('role')
                        ->withTimestamps()
                        ->as('membership');
    }

    /**
     * Determine if the user owns the given tenant.
     *
     * @param  mixed  $tenant
     * @return bool
     */
    public function ownsTenant($tenant)
    {
        if (is_null($tenant)) {
            return false;
        }

        return $this->id == $tenant->{$this->getForeignKey()};
    }

    /**
     * Determine if the user belongs to the given tenant.
     *
     * @param  mixed  $tenant
     * @return bool
     */
    public function belongsToTenant($tenant)
    {
        if (is_null($tenant)) {
            return false;
        }

        return $this->ownsTenant($tenant) || $this->tenants->contains(function ($t) use ($tenant) {
            return $t->id === $tenant->id;
        });
    }

    /**
     * Get the role that the user has on the tenant.
     *
     * @param  mixed  $tenant
     * @return \Laravel\Jetstream\Role|null
     */
    public function tenantRole($tenant)
    {
        if ($this->ownsTenant($tenant)) {
            return new OwnerRole;
        }

        if (! $this->belongsToTenant($tenant)) {
            return;
        }

        $role = $tenant->users
            ->where('id', $this->id)
            ->first()
            ->membership
            ->role;

        return $role ? Jetstream::findRole($role, $tenant) : null;
    }

    /**
     * Determine if the user has the given role on the given tenant.
     *
     * @param  mixed  $tenant
     * @param  string  $role
     * @return bool
     */
    public function hasTenantRole($tenant, string $role)
    {
        if ($this->ownsTenant($tenant)) {
            return true;
        }

        return $this->belongsToTenant($tenant) &&
               optional($this->tenantRole($tenant))->key === $role;
    }

    /**
     * Get the user's permissions for the given tenant.
     *
     * @param  mixed  $tenant
     * @return array
     */
    public function tenantPermissions($tenant)
    {
        if ($this->ownsTenant($tenant)) {
            return ['*'];
        }

        if (! $this->belongsToTenant($tenant)) {
            return [];
        }

        return (array) optional($this->tenantRole($tenant))->permissions;
    }

    /**
     * Determine if the user has the given permission on the given tenant.
     *
     * @param  mixed  $tenant
     * @param  string  $permission
     * @return bool
     */
    public function hasTenantPermission($tenant, string $permission)
    {
        if ($this->ownsTenant($tenant)) {
            return true;
        }

        if (! $this->belongsToTenant($tenant)) {
            return false;
        }

        if (in_array(HasApiTokens::class, class_uses_recursive($this)) &&
            ! $this->tokenCan($permission) &&
            $this->currentAccessToken() !== null) {
            return false;
        }

        $permissions = $this->tenantPermissions($tenant);

        return in_array($permission, $permissions) ||
               in_array('*', $permissions) ||
               (Str::endsWith($permission, ':create') && in_array('*:create', $permissions)) ||
               (Str::endsWith($permission, ':update') && in_array('*:update', $permissions));
    }

    /**
     * Determine if the user is an administrator of the entire application.
     *
     * @return bool
     */
    public function isSystemAdmin()
    {
        return (bool) $this->is_system_admin;
    }
}

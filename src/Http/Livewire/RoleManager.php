<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\Actions\CreateRole;
use Laravel\Jetstream\Actions\DeleteRole;
use Laravel\Jetstream\Actions\UpdateRole;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class RoleManager extends Component
{
    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * Indicates if a role is currently being created or edited.
     *
     * @var bool
     */
    public $managingRole = false;

    /**
     * The ID of the tenant role being edited, if any.
     *
     * @var string|null
     */
    public $roleIdBeingUpdated = null;

    /**
     * The role form state.
     *
     * @var array{key: string, name: string, description: string, permissions: list<string>}
     */
    public $roleForm = [
        'key' => '',
        'name' => '',
        'description' => '',
        'permissions' => [],
    ];

    /**
     * Indicates if the application is confirming a role deletion.
     *
     * @var bool
     */
    public $confirmingRoleDeletion = false;

    /**
     * The ID of the tenant role being deleted.
     *
     * @var string|null
     */
    public $roleIdBeingDeleted = null;

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @return void
     */
    public function mount($tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Start creating a new role.
     *
     * @return void
     */
    public function createRole()
    {
        $this->resetErrorBag();

        $this->roleIdBeingUpdated = null;

        $this->roleForm = [
            'key' => '',
            'name' => '',
            'description' => '',
            'permissions' => [],
        ];

        $this->managingRole = true;
    }

    /**
     * Start editing the role with the given key.
     *
     * Editing an application default role creates a tenant override for it.
     *
     * @param  string  $key
     * @return void
     */
    public function editRole(string $key)
    {
        $this->resetErrorBag();

        $tenantRole = $this->tenant->roles()->where('key', $key)->first();

        if ($tenantRole !== null) {
            $this->roleIdBeingUpdated = $tenantRole->id;

            $this->roleForm = [
                'key' => $tenantRole->key,
                'name' => $tenantRole->name,
                'description' => (string) $tenantRole->description,
                'permissions' => array_values($tenantRole->permissions),
            ];
        } else {
            $role = app(RoleRegistry::class)->find($key, $this->tenant->id);

            if ($role === null) {
                return;
            }

            $this->roleIdBeingUpdated = null;

            $this->roleForm = [
                'key' => $role->key,
                'name' => $role->name,
                'description' => (string) $role->description,
                'permissions' => $role->permissions,
            ];
        }

        $this->managingRole = true;
    }

    /**
     * Save the role that is being created or edited.
     *
     * @return void
     */
    public function saveRole(CreateRole $creator, UpdateRole $updater)
    {
        if ($this->roleIdBeingUpdated !== null) {
            $updater->update(
                $this->user,
                $this->tenant,
                $this->tenant->roles()->findOrFail($this->roleIdBeingUpdated),
                $this->roleForm
            );
        } else {
            $creator->create($this->user, $this->tenant, $this->roleForm);
        }

        $this->managingRole = false;

        $this->tenant->refresh();

        $this->dispatch('saved');
    }

    /**
     * Stop creating or editing a role.
     *
     * @return void
     */
    public function stopManagingRole()
    {
        $this->managingRole = false;
    }

    /**
     * Confirm that the given tenant role should be deleted.
     *
     * @param  string  $roleId
     * @return void
     */
    public function confirmRoleDeletion($roleId)
    {
        $this->resetErrorBag();

        $this->confirmingRoleDeletion = true;

        $this->roleIdBeingDeleted = $roleId;
    }

    /**
     * Delete the tenant role that is being confirmed.
     *
     * @return void
     */
    public function deleteRole(DeleteRole $deleter)
    {
        $deleter->delete(
            $this->user,
            $this->tenant,
            $this->tenant->roles()->findOrFail($this->roleIdBeingDeleted)
        );

        $this->confirmingRoleDeletion = false;

        $this->roleIdBeingDeleted = null;

        $this->tenant->refresh();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Jetstream::currentUser();
    }

    /**
     * Get all of the roles available to the tenant.
     *
     * @return list<\Laravel\Jetstream\Role>
     */
    public function getRolesProperty()
    {
        return array_values(app(RoleRegistry::class)->all($this->tenant->id));
    }

    /**
     * Get the keys of the roles that are owned by the tenant.
     *
     * @return array<string, string>
     */
    public function getCustomRoleKeysProperty()
    {
        $keys = [];

        foreach ($this->tenant->roles as $role) {
            $keys[$role->key] = $role->id;
        }

        return $keys;
    }

    /**
     * Get the application's permission catalog.
     *
     * @return list<string>
     */
    public function getAvailablePermissionsProperty()
    {
        return Jetstream::$permissions;
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('tenants.role-manager');
    }
}

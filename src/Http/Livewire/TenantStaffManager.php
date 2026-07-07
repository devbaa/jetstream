<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Contracts\AddsTenantStaff;
use Laravel\Jetstream\Contracts\RemovesTenantStaff;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;
use Laravel\Jetstream\RoleRegistry;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class TenantStaffManager extends Component
{
    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * Indicates if a user's role is currently being managed.
     *
     * @var bool
     */
    public $currentlyManagingRole = false;

    /**
     * The user that is having their role managed.
     *
     * @var \App\Models\User|null
     */
    public $managingRoleFor;

    /**
     * The current role for the user that is having their role managed.
     *
     * @var string|null
     */
    public $currentRole;

    /**
     * Indicates if the application is confirming if a user wishes to leave the tenant.
     *
     * @var bool
     */
    public $confirmingLeavingTenant = false;

    /**
     * Indicates if the application is confirming if a staff member should be removed.
     *
     * @var bool
     */
    public $confirmingStaffRemoval = false;

    /**
     * The ID of the staff member being removed.
     *
     * @var string|null
     */
    public $staffIdBeingRemoved = null;

    /**
     * The "add staff member" form state.
     *
     * @var array{email: string, role: string|null}
     */
    public $addStaffForm = [
        'email' => '',
        'role' => null,
    ];

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
     * Add a new staff member to the tenant.
     *
     * @return void
     */
    public function addStaffMember(AddsTenantStaff $adder)
    {
        $this->resetErrorBag();

        $adder->add(
            $this->user,
            $this->tenant,
            $this->addStaffForm['email'],
            $this->addStaffForm['role']
        );

        $this->addStaffForm = [
            'email' => '',
            'role' => null,
        ];

        $this->tenant->refresh();

        $this->dispatch('saved');
    }

    /**
     * Allow the given staff member's role to be managed.
     *
     * @param  string  $userId
     * @return void
     */
    public function manageRole($userId)
    {
        $this->currentlyManagingRole = true;
        $this->managingRoleFor = Jetstream::findUserByIdOrFail($userId);
        $this->currentRole = $this->managingRoleFor->tenantRole($this->tenant)?->key;
    }

    /**
     * Save the role for the staff member being managed.
     *
     * @param  \Laravel\Jetstream\Actions\UpdateTenantStaffRole  $updater
     * @return void
     */
    public function updateRole(UpdateTenantStaffRole $updater)
    {
        abort_if(is_null($this->managingRoleFor), 403);

        $updater->update(
            $this->user,
            $this->tenant,
            $this->managingRoleFor->id,
            $this->currentRole ?? ''
        );

        $this->tenant->refresh();

        $this->stopManagingRole();
    }

    /**
     * Stop managing the role of a given staff member.
     *
     * @return void
     */
    public function stopManagingRole()
    {
        $this->currentlyManagingRole = false;
    }

    /**
     * Remove the currently authenticated user from the tenant.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function leaveTenant(RemovesTenantStaff $remover)
    {
        $remover->remove(
            $this->user,
            $this->tenant,
            $this->user
        );

        $this->confirmingLeavingTenant = false;

        $this->tenant->refresh();

        return redirect(Jetstream::homePath());
    }

    /**
     * Toggle whether the given staff member's membership is frozen.
     *
     * Frozen staff members remain on the tenant but lose all access to it
     * until unfrozen. Owners cannot be frozen.
     *
     * @param  string  $userId
     * @return void
     */
    public function toggleStaffFreeze($userId)
    {
        Gate::forUser($this->user)->authorize('updateTenantStaff', $this->tenant);

        $staff = Jetstream::findUserByIdOrFail($userId);

        abort_if($staff->ownsTenant($this->tenant), 403);

        $frozen = $staff->tenantMembershipIsFrozen($this->tenant);

        $this->tenant->users()->updateExistingPivot($staff->id, [
            'frozen_at' => $frozen ? null : now(),
        ]);

        if (! $frozen && $staff->getAttribute('current_tenant_id') === $this->tenant->id) {
            $staff->forceFill(['current_tenant_id' => null])->save();
        }

        $this->tenant->refresh();

        $this->dispatch('saved');
    }

    /**
     * Confirm that the given staff member should be removed.
     *
     * @param  string  $userId
     * @return void
     */
    public function confirmStaffRemoval($userId)
    {
        $this->confirmingStaffRemoval = true;

        $this->staffIdBeingRemoved = $userId;
    }

    /**
     * Remove a staff member from the tenant.
     *
     * @return void
     */
    public function removeStaffMember(RemovesTenantStaff $remover)
    {
        abort_if(is_null($this->staffIdBeingRemoved), 403);

        $remover->remove(
            $this->user,
            $this->tenant,
            Jetstream::findUserByIdOrFail($this->staffIdBeingRemoved)
        );

        $this->confirmingStaffRemoval = false;

        $this->staffIdBeingRemoved = null;

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
     * Get the available staff roles.
     *
     * @return list<\Laravel\Jetstream\Role>
     */
    public function getRolesProperty()
    {
        return array_values(collect(app(RoleRegistry::class)->all($this->tenant->id))->map(function (Role $role): Role {
            $name = __($role->name);
            $description = __($role->description ?? '');

            return (new Role(
                $role->key,
                is_string($name) ? $name : $role->name,
                $role->permissions
            ))->description(is_string($description) ? $description : '');
        })->all());
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('tenants.tenant-staff-manager');
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Contracts\AddsTenantStaff;
use Laravel\Jetstream\Contracts\RemovesTenantStaff;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;
use Laravel\Jetstream\RoleRegistry;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class TenantStaffManager extends Component
{
    /**
     * The tenant instance.
     *
     * @var mixed
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
     * @var mixed
     */
    public $managingRoleFor;

    /**
     * The current role for the user that is having their role managed.
     *
     * @var string
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
     * @var int|null
     */
    public $staffIdBeingRemoved = null;

    /**
     * The "add staff member" form state.
     *
     * @var array
     */
    public $addStaffForm = [
        'email' => '',
        'role' => null,
    ];

    /**
     * Mount the component.
     *
     * @param  mixed  $tenant
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

        $this->tenant = $this->tenant->fresh();

        $this->dispatch('saved');
    }

    /**
     * Allow the given staff member's role to be managed.
     *
     * @param  int  $userId
     * @return void
     */
    public function manageRole($userId)
    {
        $this->currentlyManagingRole = true;
        $this->managingRoleFor = Jetstream::findUserByIdOrFail($userId);
        $this->currentRole = $this->managingRoleFor->tenantRole($this->tenant)->key;
    }

    /**
     * Save the role for the staff member being managed.
     *
     * @param  \Laravel\Jetstream\Actions\UpdateTenantStaffRole  $updater
     * @return void
     */
    public function updateRole(UpdateTenantStaffRole $updater)
    {
        $updater->update(
            $this->user,
            $this->tenant,
            $this->managingRoleFor->id,
            $this->currentRole
        );

        $this->tenant = $this->tenant->fresh();

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

        $this->tenant = $this->tenant->fresh();

        return redirect(config('fortify.home'));
    }

    /**
     * Confirm that the given staff member should be removed.
     *
     * @param  int  $userId
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
        $remover->remove(
            $this->user,
            $this->tenant,
            Jetstream::findUserByIdOrFail($this->staffIdBeingRemoved)
        );

        $this->confirmingStaffRemoval = false;

        $this->staffIdBeingRemoved = null;

        $this->tenant = $this->tenant->fresh();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Auth::user();
    }

    /**
     * Get the available staff roles.
     *
     * @return array
     */
    public function getRolesProperty()
    {
        return collect(app(RoleRegistry::class)->all($this->tenant->id))->transform(function ($role) {
            return with($role->jsonSerialize(), function ($data) {
                return (new Role(
                    $data['key'],
                    $data['name'],
                    $data['permissions']
                ))->description($data['description']);
            });
        })->values()->all();
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

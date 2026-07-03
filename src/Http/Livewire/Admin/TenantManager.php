<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Admin;

use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\CreatesTenants;
use Laravel\Jetstream\Contracts\DeletesTenants;
use Laravel\Jetstream\Events\TenantFrozen;
use Laravel\Jetstream\Events\TenantUnfrozen;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Livewire\Component;

// v2: impersonation

/**
 * @property-read \App\Models\User $user
 */
class TenantManager extends Component
{
    /**
     * The tenant search query.
     *
     * @var string
     */
    public $search = '';

    /**
     * Indicates if a tenant is currently being created.
     *
     * @var bool
     */
    public $creatingTenant = false;

    /**
     * The "create tenant" form state.
     *
     * @var array{name: string, owner_email: string}
     */
    public $createTenantForm = [
        'name' => '',
        'owner_email' => '',
    ];

    /**
     * Indicates if the application is confirming a tenant deletion.
     *
     * @var bool
     */
    public $confirmingTenantDeletion = false;

    /**
     * The ID of the tenant being deleted.
     *
     * @var int|null
     */
    public $tenantIdBeingDeleted = null;

    /**
     * Start creating a new tenant.
     *
     * @return void
     */
    public function createTenant()
    {
        $this->resetErrorBag();

        $this->createTenantForm = [
            'name' => '',
            'owner_email' => '',
        ];

        $this->creatingTenant = true;
    }

    /**
     * Save the tenant that is being created, assigning ownership by email.
     *
     * @return void
     */
    public function saveTenant(CreatesTenants $creator)
    {
        Validator::make($this->createTenantForm, [
            'name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'exists:users,email'],
        ], [
            'owner_email.exists' => __('We were unable to find a registered user with this email address.'),
        ])->validateWithBag('createTenant');

        $owner = Jetstream::findUserByEmailOrFail($this->createTenantForm['owner_email']);

        app(TenantContext::class)->bypass(function () use ($creator, $owner) {
            $creator->create($owner, ['name' => $this->createTenantForm['name']]);
        });

        $this->creatingTenant = false;

        $this->dispatch('saved');
    }

    /**
     * Toggle whether the given tenant allows customer self-registration.
     *
     * @param  int  $tenantId
     * @return void
     */
    public function toggleCustomerRegistration($tenantId)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($tenantId);

        $tenant->forceFill([
            'allow_customer_registration' => ! $tenant->allow_customer_registration,
        ])->save();
    }

    /**
     * Toggle whether the given tenant is frozen.
     *
     * A frozen tenant's staff and customers lose access to the tenant until
     * it is unfrozen.
     *
     * @param  int  $tenantId
     * @return void
     */
    public function toggleTenantFreeze($tenantId)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($tenantId);

        $frozen = $tenant->isFrozen();

        $tenant->forceFill([
            'frozen_at' => $frozen ? null : now(),
        ])->save();

        $frozen
            ? TenantUnfrozen::dispatch($tenant)
            : TenantFrozen::dispatch($tenant);

        $this->dispatch('saved');
    }

    /**
     * Confirm that the given tenant should be deleted.
     *
     * @param  int  $tenantId
     * @return void
     */
    public function confirmTenantDeletion($tenantId)
    {
        $this->confirmingTenantDeletion = true;

        $this->tenantIdBeingDeleted = $tenantId;
    }

    /**
     * Delete the tenant that is being confirmed.
     *
     * @return void
     */
    public function deleteTenant(DeletesTenants $deleter)
    {
        $tenant = Jetstream::newTenantModel()->findOrFail($this->tenantIdBeingDeleted);

        app(TenantContext::class)->bypass(function () use ($deleter, $tenant) {
            $deleter->delete($tenant);
        });

        $this->confirmingTenantDeletion = false;

        $this->tenantIdBeingDeleted = null;
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
     * Get the tenants matching the current search query.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\Tenant>
     */
    public function getTenantsProperty()
    {
        return app(TenantContext::class)->bypass(function () {
            return Jetstream::newTenantModel()
                ->newQuery()
                ->with('owner')
                ->withCount(['users', 'customerAccounts'])
                ->when($this->search !== '', function ($query) {
                    $query->where(function ($query) {
                        $query->where('name', 'like', '%'.$this->search.'%')
                              ->orWhere('slug', 'like', '%'.$this->search.'%');
                    });
                })
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('admin.tenant-manager');
    }
}

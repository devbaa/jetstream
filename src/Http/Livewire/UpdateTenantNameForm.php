<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Contracts\UpdatesTenantNames;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class UpdateTenantNameForm extends Component
{
    /**
     * The tenant instance.
     *
     * @var \Laravel\Jetstream\Tenant
     */
    public $tenant;

    /**
     * The component's state.
     *
     * @var array<string, mixed>
     */
    public $state = [];

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Tenant  $tenant
     * @return void
     */
    public function mount($tenant)
    {
        $this->tenant = $tenant;

        $this->state = array_filter($tenant->withoutRelations()->toArray(), 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Update the tenant's name.
     *
     * @param  \Laravel\Jetstream\Contracts\UpdatesTenantNames  $updater
     * @return void
     */
    public function updateTenantName(UpdatesTenantNames $updater)
    {
        $this->resetErrorBag();

        $updater->update($this->user, $this->tenant, $this->state);

        $this->dispatch('saved');

        $this->dispatch('refresh-navigation-menu');
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
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('tenants.update-tenant-name-form');
    }
}

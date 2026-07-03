<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Contracts\UpdatesTenantNames;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class UpdateTenantNameForm extends Component
{
    /**
     * The tenant instance.
     *
     * @var mixed
     */
    public $tenant;

    /**
     * The component's state.
     *
     * @var array
     */
    public $state = [];

    /**
     * Mount the component.
     *
     * @param  mixed  $tenant
     * @return void
     */
    public function mount($tenant)
    {
        $this->tenant = $tenant;

        $this->state = $tenant->withoutRelations()->toArray();
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
        return Auth::user();
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

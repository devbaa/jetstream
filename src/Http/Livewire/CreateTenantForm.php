<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Contracts\CreatesTenants;
use Laravel\Jetstream\RedirectsActions;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class CreateTenantForm extends Component
{
    use RedirectsActions;

    /**
     * The component's state.
     *
     * @var array<string, mixed>
     */
    public $state = [];

    /**
     * Create a new tenant.
     *
     * @param  \Laravel\Jetstream\Contracts\CreatesTenants  $creator
     * @return mixed
     */
    public function createTenant(CreatesTenants $creator)
    {
        $this->resetErrorBag();

        $creator->create(Jetstream::currentUser(), $this->state);

        return $this->redirectPath($creator);
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
        return view('tenants.create-tenant-form');
    }
}

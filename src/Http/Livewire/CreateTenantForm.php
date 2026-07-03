<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Contracts\CreatesTenants;
use Laravel\Jetstream\RedirectsActions;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class CreateTenantForm extends Component
{
    use RedirectsActions;

    /**
     * The component's state.
     *
     * @var array
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

        $creator->create(Auth::user(), $this->state);

        return $this->redirectPath($creator);
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
        return view('tenants.create-tenant-form');
    }
}

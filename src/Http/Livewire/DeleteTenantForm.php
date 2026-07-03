<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Actions\ValidateTenantDeletion;
use Laravel\Jetstream\Contracts\DeletesTenants;
use Laravel\Jetstream\RedirectsActions;
use Livewire\Component;

class DeleteTenantForm extends Component
{
    use RedirectsActions;

    /**
     * The tenant instance.
     *
     * @var mixed
     */
    public $tenant;

    /**
     * Indicates if tenant deletion is being confirmed.
     *
     * @var bool
     */
    public $confirmingTenantDeletion = false;

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
     * Delete the tenant.
     *
     * @param  \Laravel\Jetstream\Actions\ValidateTenantDeletion  $validator
     * @param  \Laravel\Jetstream\Contracts\DeletesTenants  $deleter
     * @return mixed
     */
    public function deleteTenant(ValidateTenantDeletion $validator, DeletesTenants $deleter)
    {
        $validator->validate(Auth::user(), $this->tenant);

        $deleter->delete($this->tenant);

        $this->tenant = null;

        return $this->redirectPath($deleter);
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('tenants.delete-tenant-form');
    }
}

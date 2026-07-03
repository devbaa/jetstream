<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Portal;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class UpdateAccountNameForm extends Component
{
    /**
     * The customer account instance.
     *
     * @var mixed
     */
    public $account;

    /**
     * The component's state.
     *
     * @var array
     */
    public $state = [];

    /**
     * Mount the component.
     *
     * @param  mixed  $account
     * @return void
     */
    public function mount($account)
    {
        $this->account = $account;

        $this->state = $account->withoutRelations()->toArray();
    }

    /**
     * Update the customer account's name.
     *
     * @return void
     */
    public function updateAccountName()
    {
        $this->resetErrorBag();

        Gate::forUser($this->user)->authorize('update', $this->account);

        Validator::make($this->state, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('updateAccountName');

        $this->account->forceFill([
            'name' => $this->state['name'],
        ])->save();

        $this->dispatch('saved');
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
        return view('portal.update-account-name-form');
    }
}

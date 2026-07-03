<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Portal;

use Laravel\Jetstream\Jetstream;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class UpdateAccountNameForm extends Component
{
    /**
     * The customer account instance.
     *
     * @var \Laravel\Jetstream\CustomerAccount
     */
    public $account;

    /**
     * The component's state.
     *
     * @var array<string, mixed>
     */
    public $state = [];

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\CustomerAccount  $account
     * @return void
     */
    public function mount($account)
    {
        $this->account = $account;

        $this->state = array_filter($account->withoutRelations()->toArray(), 'is_string', ARRAY_FILTER_USE_KEY);
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
        return Jetstream::currentUser();
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

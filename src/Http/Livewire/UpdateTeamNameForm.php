<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class UpdateTeamNameForm extends Component
{
    /**
     * The team instance.
     *
     * @var \Laravel\Jetstream\Team
     */
    public $team;

    /**
     * The component's state.
     *
     * @var array<string, mixed>
     */
    public $state = [];

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Team  $team
     * @return void
     */
    public function mount($team)
    {
        $this->team = $team;

        $this->state = array_filter($team->withoutRelations()->toArray(), 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Update the team's name.
     *
     * @param  \Laravel\Jetstream\Contracts\UpdatesTeamNames  $updater
     * @return void
     */
    public function updateTeamName(UpdatesTeamNames $updater)
    {
        $this->resetErrorBag();

        $updater->update($this->user, $this->team, $this->state);

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
        return view('teams.update-team-name-form');
    }
}

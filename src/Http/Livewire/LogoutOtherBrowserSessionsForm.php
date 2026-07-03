<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Jetstream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Agent;
use Livewire\Component;

class LogoutOtherBrowserSessionsForm extends Component
{
    /**
     * Indicates if logout is being confirmed.
     *
     * @var bool
     */
    public $confirmingLogout = false;

    /**
     * The user's current password.
     *
     * @var string
     */
    public $password = '';

    /**
     * Confirm that the user would like to log out from other browser sessions.
     *
     * @return void
     */
    public function confirmLogout()
    {
        $this->password = '';

        $this->dispatch('confirming-logout-other-browser-sessions');

        $this->confirmingLogout = true;
    }

    /**
     * Log out from other browser sessions.
     *
     * @param  \Illuminate\Contracts\Auth\StatefulGuard  $guard
     * @return void
     */
    public function logoutOtherBrowserSessions(StatefulGuard $guard)
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $this->resetErrorBag();

        if (! Hash::check($this->password, Jetstream::currentUser()->password)) {
            throw ValidationException::withMessages([
                'password' => [__('This password does not match our records.')],
            ]);
        }

        $guard->logoutOtherDevices($this->password);

        $this->deleteOtherSessionRecords();

        request()->session()->put([
            'password_hash_'.Auth::getDefaultDriver() => Jetstream::currentUser()->getAuthPassword(),
        ]);

        $this->confirmingLogout = false;

        $this->dispatch('loggedOut');
    }

    /**
     * Delete the other browser session records from storage.
     *
     * @return void
     */
    protected function deleteOtherSessionRecords()
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(self::sessionConnection())->table(self::sessionTable())
            ->where('user_id', Jetstream::currentUser()->getAuthIdentifier())
            ->where('id', '!=', request()->session()->getId())
            ->delete();
    }

    /**
     * Get the current sessions.
     *
     * @return \Illuminate\Support\Collection<int, object{agent: \Laravel\Jetstream\Agent, ip_address: string|null, is_current_device: bool, last_active: string}&\stdClass>
     */
    public function getSessionsProperty()
    {
        $rows = config('session.driver') === 'database'
            ? DB::connection(self::sessionConnection())->table(self::sessionTable())
                    ->where('user_id', Jetstream::currentUser()->getAuthIdentifier())
                    ->orderBy('last_activity', 'desc')
                    ->get()
                    ->all()
            : [];

        return collect($rows)->map(function (\stdClass $session) {
            return (object) [
                'agent' => $this->createAgent($session),
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                'is_current_device' => $session->id === request()->session()->getId(),
                'last_active' => Carbon::createFromTimestamp(is_int($session->last_activity) || is_string($session->last_activity) ? $session->last_activity : 0)->diffForHumans(),
            ];
        });
    }

    /**
     * Create a new agent instance from the given session.
     *
     * @param  \stdClass  $session
     * @return \Laravel\Jetstream\Agent
     */
    protected function createAgent($session)
    {
        return tap(new Agent(), function (Agent $agent) use ($session): void {
            $agent->setUserAgent(is_string($session->user_agent) ? $session->user_agent : '');
        });
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('profile.logout-other-browser-sessions-form');
    }

    /**
     * Get the configured session database connection name.
     */
    protected static function sessionConnection(): ?string
    {
        $connection = config('session.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Get the configured session table name.
     */
    protected static function sessionTable(): string
    {
        $table = config('session.table', 'sessions');

        return is_string($table) ? $table : 'sessions';
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Fortify\Features;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Component;

/**
 * @property-read \App\Models\User|null $user
 */
class PasskeyManager extends Component
{
    use ConfirmsPasswords;

    /**
     * The name for the passkey that is being registered.
     */
    public string $passkeyName = '';

    /**
     * Ask the browser to begin the WebAuthn registration ceremony.
     *
     * The actual ceremony runs client side against Fortify's passkey
     * endpoints; this action only validates the name and hands off.
     */
    public function registerPasskey(): void
    {
        $this->resetErrorBag();

        $this->ensurePasswordIsConfirmedWhenRequired();

        $name = trim($this->passkeyName);

        if ($name === '') {
            $this->addError('passkey_name', __('Please provide a name for the passkey.'));

            return;
        }

        $this->dispatch('passkey-registration-requested', name: $name);
    }

    /**
     * Reset the form after the browser reports a successful registration.
     */
    public function finishPasskeyRegistration(): void
    {
        $this->passkeyName = '';

        $this->dispatch('saved');
    }

    /**
     * Surface a client side registration failure to the user.
     */
    public function reportPasskeyRegistrationError(string $message): void
    {
        $this->addError('passkey_name', $message !== ''
            ? $message
            : __('The passkey could not be registered.'));
    }

    /**
     * Delete the given passkey.
     */
    public function deletePasskey(int $passkeyId): void
    {
        $this->ensurePasswordIsConfirmedWhenRequired();

        Auth::user()?->passkeys()->whereKey($passkeyId)->delete();
    }

    /**
     * Require a recent password confirmation when the feature demands it.
     */
    protected function ensurePasswordIsConfirmedWhenRequired(): void
    {
        if (Features::optionEnabled(Features::passkeys(), 'confirmPassword')) {
            $this->ensurePasswordIsConfirmed();
        }
    }

    /**
     * Get the current user of the application.
     */
    public function getUserProperty(): mixed
    {
        return Auth::user();
    }

    /**
     * Get the user's registered passkeys.
     */
    public function getPasskeysProperty(): mixed
    {
        return Auth::user()?->passkeys()->latest()->get();
    }

    /**
     * Render the component.
     */
    public function render(): View
    {
        return view('profile.passkey-manager');
    }
}

<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\RecoveryEmailVerification;
use Livewire\Component;

/**
 * Lets a user maintain their account recovery channels: a phone number and
 * a verified secondary email address that can receive password reset links.
 *
 * @property-read \App\Models\User $user
 */
class UpdateRecoveryChannelsForm extends Component
{
    /**
     * The component's state.
     *
     * @var array{phone: string, recovery_email: string}
     */
    public $state = [
        'phone' => '',
        'recovery_email' => '',
    ];

    /**
     * Mount the component.
     *
     * @return void
     */
    public function mount()
    {
        $user = $this->user;

        $this->state = [
            'phone' => (string) $user->phone,
            'recovery_email' => (string) $user->recovery_email,
        ];
    }

    /**
     * Update the user's recovery channels.
     *
     * Changing the recovery email resets its verification status and sends
     * a fresh verification link to the new address.
     *
     * @return void
     */
    public function updateRecoveryChannels()
    {
        $this->resetErrorBag();

        $user = $this->user;

        $validated = Validator::make($this->state, [
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-().]{7,31}$/'],
            'recovery_email' => ['nullable', 'string', 'email', 'max:255', \Illuminate\Validation\Rule::notIn([$user->email])],
        ], [
            'recovery_email.not_in' => __('Your recovery email must be different from your primary email address.'),
        ])->validateWithBag('updateRecoveryChannels');

        $phone = is_string($validated['phone'] ?? null) && $validated['phone'] !== '' ? $validated['phone'] : null;
        $recoveryEmail = is_string($validated['recovery_email'] ?? null) && $validated['recovery_email'] !== '' ? $validated['recovery_email'] : null;

        $recoveryEmailChanged = $recoveryEmail !== $user->recovery_email;

        $user->forceFill([
            'phone' => $phone,
            'recovery_email' => $recoveryEmail,
            'recovery_email_verified_at' => $recoveryEmailChanged ? null : $user->recovery_email_verified_at,
        ])->save();

        if ($recoveryEmailChanged && $recoveryEmail !== null) {
            Mail::to($recoveryEmail)->send(new RecoveryEmailVerification($user));
        }

        $this->dispatch('saved');
    }

    /**
     * Resend the verification link for the user's recovery email.
     *
     * @return void
     */
    public function sendRecoveryEmailVerification()
    {
        $user = $this->user;

        if (is_string($user->recovery_email) && $user->recovery_email_verified_at === null) {
            Mail::to($user->recovery_email)->send(new RecoveryEmailVerification($user));

            $this->dispatch('recovery-email-verification-sent');
        }
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
        return view('profile.update-recovery-channels-form');
    }
}

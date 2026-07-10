<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\SendsPhoneVerifications;
use Laravel\Jetstream\Http\Livewire\Concerns\WithRateLimiting;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\RecoveryEmailVerification;
use Laravel\Jetstream\PhoneCountry;
use Livewire\Component;

/**
 * Lets a user maintain their account recovery channels: a country-coded
 * phone number and a verified secondary email address that can receive
 * password reset links.
 *
 * @property-read \App\Models\User $user
 */
class UpdateRecoveryChannelsForm extends Component
{
    use WithRateLimiting;

    /**
     * The component's state.
     *
     * @var array{phone_country: string, phone: string, recovery_email: string}
     */
    public $state = [
        'phone_country' => '',
        'phone' => '',
        'recovery_email' => '',
    ];

    /**
     * The phone verification code that is being confirmed.
     *
     * @var string
     */
    public $phoneVerificationCode = '';

    /**
     * Mount the component.
     *
     * @return void
     */
    public function mount()
    {
        $user = $this->user;

        $this->state = [
            'phone_country' => (string) $user->phone_country,
            'phone' => (string) $user->phone,
            'recovery_email' => (string) $user->recovery_email,
        ];
    }

    /**
     * Update the user's recovery channels.
     *
     * Changing the recovery email or phone number resets the corresponding
     * verification status. A fresh verification link is sent to a new
     * recovery email automatically.
     *
     * @return void
     */
    public function updateRecoveryChannels()
    {
        $this->resetErrorBag();

        $user = $this->user;

        $validated = Validator::make($this->state, [
            'phone_country' => ['nullable', 'string', 'size:2', 'required_with:phone', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! PhoneCountry::isValid($value)) {
                    $fail(__('The selected country is invalid.'));
                }
            }],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9\s\-().]{4,31}$/'],
            'recovery_email' => ['nullable', 'string', 'email', 'max:255', \Illuminate\Validation\Rule::notIn([$user->email])],
        ], [
            'phone.regex' => __('Enter the national number without the country code.'),
            'recovery_email.not_in' => __('Your recovery email must be different from your primary email address.'),
        ])->validateWithBag('updateRecoveryChannels');

        $country = is_string($validated['phone_country'] ?? null) && $validated['phone_country'] !== '' ? $validated['phone_country'] : null;
        $nationalNumber = is_string($validated['phone'] ?? null) && $validated['phone'] !== '' ? $validated['phone'] : null;
        $recoveryEmail = is_string($validated['recovery_email'] ?? null) && $validated['recovery_email'] !== '' ? $validated['recovery_email'] : null;

        $phone = null;

        if ($country !== null && $nationalNumber !== null) {
            $phone = PhoneCountry::toE164($country, $nationalNumber);

            if ($phone === null) {
                $this->addError('phone', __('This phone number is invalid for the selected country.'));

                return;
            }
        }

        $phoneChanged = $phone !== $user->phone;
        $recoveryEmailChanged = $recoveryEmail !== $user->recovery_email;

        $user->forceFill([
            'phone' => $phone,
            'phone_country' => $phone !== null ? $country : null,
            'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
            'phone_verification_code' => $phoneChanged ? null : $user->phone_verification_code,
            'phone_verification_expires_at' => $phoneChanged ? null : $user->phone_verification_expires_at,
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
            $this->rateLimit('recovery-email-verification', maxAttempts: 5, decaySeconds: 60);

            Mail::to($user->recovery_email)->send(new RecoveryEmailVerification($user));

            $this->dispatch('recovery-email-verification-sent');
        }
    }

    /**
     * Send a verification code to the user's phone number.
     *
     * @return void
     */
    public function sendPhoneVerification()
    {
        abort_unless(Jetstream::phoneVerificationEnabled(), 403);

        $user = $this->user;

        if (! is_string($user->phone) || $user->phone_verified_at !== null) {
            return;
        }

        $this->rateLimit('phone-verification-send', maxAttempts: 5, decaySeconds: 60, errorBag: 'phone');

        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'phone_verification_code' => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        app(SendsPhoneVerifications::class)->send($user, $code);

        $this->dispatch('phone-verification-sent');
    }

    /**
     * Confirm the verification code that was sent to the user's phone.
     *
     * @return void
     */
    public function confirmPhoneVerification()
    {
        abort_unless(Jetstream::phoneVerificationEnabled(), 403);

        $user = $this->user;

        $this->rateLimit('phone-verification-confirm', maxAttempts: 6, decaySeconds: 60, errorBag: 'phone_verification_code');

        $expired = $user->phone_verification_expires_at === null ||
                   $user->phone_verification_expires_at->isPast();

        if (! is_string($user->phone_verification_code) ||
            $expired ||
            ! Hash::check($this->phoneVerificationCode, $user->phone_verification_code)) {
            $this->addError('phone_verification_code', __('This verification code is invalid or has expired.'));

            return;
        }

        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ])->save();

        $this->clearRateLimit('phone-verification-confirm');

        $this->phoneVerificationCode = '';

        $this->dispatch('saved');
    }

    /**
     * Determine if the application can verify phone numbers.
     *
     * @return bool
     */
    public function getPhoneVerificationEnabledProperty()
    {
        return Jetstream::phoneVerificationEnabled();
    }

    /**
     * Get the supported phone countries.
     *
     * @return array<string, array{name: string, dial: string}>
     */
    public function getPhoneCountriesProperty()
    {
        return PhoneCountry::all();
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

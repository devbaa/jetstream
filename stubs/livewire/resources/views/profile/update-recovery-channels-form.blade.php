<x-form-section submit="updateRecoveryChannels">
    <x-slot name="title">
        {{ __('Account Recovery') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Add a phone number and a secondary email address that can be used to recover access to your account.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Phone -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="phone" value="{{ __('Phone Number') }}" />

            <div class="mt-1 flex">
                <select id="phone_country" wire:model="state.phone_country"
                        class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm me-2 w-40">
                    <option value="">{{ __('Country') }}</option>

                    @foreach ($this->phoneCountries as $iso => $country)
                        <option value="{{ $iso }}">{{ $country['name'] }} (+{{ $country['dial'] }})</option>
                    @endforeach
                </select>

                <x-input id="phone" type="tel" class="block w-full" wire:model="state.phone" autocomplete="tel-national" placeholder="{{ __('National number') }}" />
            </div>

            <x-input-error for="phone_country" class="mt-2" />
            <x-input-error for="phone" class="mt-2" />

            @if ($this->user->phone)
                @if ($this->user->phone_verified_at)
                    <p class="text-sm mt-2 text-green-600 dark:text-green-400">
                        {{ __('Your phone number (:phone) is verified.', ['phone' => $this->user->phone]) }}
                    </p>
                @elseif ($this->phoneVerificationEnabled)
                    <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                        {{ __('Your phone number is unverified.') }}

                        <button type="button" class="underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100" wire:click.prevent="sendPhoneVerification">
                            {{ __('Send a verification code.') }}
                        </button>
                    </p>

                    <x-action-message class="mt-2 font-medium text-sm text-green-600 dark:text-green-400" on="phone-verification-sent">
                        {{ __('A verification code has been sent to your phone.') }}
                    </x-action-message>

                    <div class="mt-2 flex items-center">
                        <x-input type="text" class="w-40" inputmode="numeric" placeholder="{{ __('Code') }}" wire:model="phoneVerificationCode" />

                        <x-secondary-button class="ms-2" type="button" wire:click.prevent="confirmPhoneVerification" wire:loading.attr="disabled">
                            {{ __('Confirm') }}
                        </x-secondary-button>
                    </div>

                    <x-input-error for="phone_verification_code" class="mt-2" />
                @else
                    <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                        {{ __('Phone verification is not active right now, so your number is stored unverified.') }}
                    </p>
                @endif
            @endif
        </div>

        <!-- Recovery Email -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="recovery_email" value="{{ __('Recovery Email') }}" />
            <x-input id="recovery_email" type="email" class="mt-1 block w-full" wire:model="state.recovery_email" autocomplete="email" />
            <x-input-error for="recovery_email" class="mt-2" />

            @if ($this->user->recovery_email)
                @if ($this->user->recovery_email_verified_at)
                    <p class="text-sm mt-2 text-green-600 dark:text-green-400">
                        {{ __('Your recovery email is verified and can receive password reset links.') }}
                    </p>
                @else
                    <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                        {{ __('Your recovery email is unverified.') }}

                        <button type="button" class="underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100" wire:click.prevent="sendRecoveryEmailVerification">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    <x-action-message class="mt-2 font-medium text-sm text-green-600 dark:text-green-400" on="recovery-email-verification-sent">
                        {{ __('A new verification link has been sent to your recovery email address.') }}
                    </x-action-message>
                @endif
            @endif
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Saved.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled">
            {{ __('Save') }}
        </x-button>
    </x-slot>
</x-form-section>

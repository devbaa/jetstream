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
            <x-input id="phone" type="tel" class="mt-1 block w-full" wire:model="state.phone" autocomplete="tel" placeholder="+90 555 000 00 00" />
            <x-input-error for="phone" class="mt-2" />
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

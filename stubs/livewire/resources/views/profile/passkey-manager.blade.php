<x-action-section>
    <x-slot name="title">
        {{ __('Passkeys') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign in securely without a password using your device\'s screen lock or a hardware security key.') }}
    </x-slot>

    <x-slot name="content">
        <div x-data
             x-on:passkey-registration-requested.window="
                window.Passkeys.register({ name: $event.detail.name })
                    .then(() => $wire.finishPasskeyRegistration())
                    .catch((error) => $wire.reportPasskeyRegistrationError(error?.message ?? ''))
             ">
            <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
                {{ __('Passkeys are a phishing-resistant replacement for passwords. When you add a passkey, your browser stores a credential that only works on this site.') }}
            </div>

            <template x-if="! window.Passkeys || ! window.Passkeys.isSupported()">
                <div class="mt-3 max-w-xl text-sm text-amber-600 dark:text-amber-400">
                    {{ __('Your browser does not support passkeys.') }}
                </div>
            </template>

            <!-- Registered Passkeys -->
            @if ($this->passkeys && $this->passkeys->isNotEmpty())
                <div class="mt-5 space-y-4">
                    @foreach ($this->passkeys as $passkey)
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="size-6 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                </svg>

                                <div class="ms-3 leading-tight">
                                    <div class="text-sm text-gray-900 dark:text-white">
                                        {{ $passkey->name }}

                                        @if ($passkey->authenticator)
                                            <span class="ms-1 text-xs text-gray-500">({{ $passkey->authenticator }})</span>
                                        @endif
                                    </div>

                                    <div class="text-xs text-gray-500">
                                        {{ __('Added :date', ['date' => $passkey->created_at?->diffForHumans()]) }}

                                        @if ($passkey->last_used_at)
                                            &middot; {{ __('Last used :date', ['date' => $passkey->last_used_at->diffForHumans()]) }}
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <x-confirms-password wire:then="deletePasskey({{ $passkey->id }})">
                                <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none">
                                    {{ __('Remove') }}
                                </button>
                            </x-confirms-password>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Register New Passkey -->
            <div class="mt-5 flex items-end gap-3">
                <div class="w-full max-w-xs">
                    <x-label for="passkey-name" value="{{ __('Passkey Name') }}" />
                    <x-input id="passkey-name" type="text" class="mt-1 block w-full" wire:model="passkeyName" placeholder="{{ __('e.g. MacBook Pro') }}" />
                </div>

                <x-confirms-password wire:then="registerPasskey">
                    <x-button type="button">
                        {{ __('Add Passkey') }}
                    </x-button>
                </x-confirms-password>
            </div>

            <x-input-error for="passkey_name" class="mt-2" />

            <x-action-message class="mt-3" on="saved">
                {{ __('Passkey registered.') }}
            </x-action-message>
        </div>
    </x-slot>
</x-action-section>

<div>
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
        <div class="flex items-center justify-between">
            <div class="w-full max-w-sm">
                <x-input type="text" class="block w-full" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search users...') }}" />
            </div>

            <x-action-message class="ms-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>

        <div class="mt-6 space-y-6">
            @forelse ($this->users as $user)
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div>
                        <div class="text-gray-900 dark:text-white">
                            {{ $user->fullName() }}

                            @if ($user->isBlocked())
                                <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">{{ __('Blocked') }}</span>
                            @endif

                            @if ($user->isSystemAdmin())
                                <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">{{ __('Admin') }}</span>
                            @endif
                        </div>

                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $user->email }}

                            @if ($user->isBlocked() && $user->blocked_reason)
                                &middot; {{ __('Blocked: :reason', ['reason' => $user->blocked_reason]) }}
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center">
                        @if ($user->two_factor_secret)
                            <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                    wire:click="resetTwoFactorAuthentication({{ $user->id }})"
                                    wire:confirm="{{ __('Reset two-factor authentication for this user?') }}">
                                {{ __('Reset 2FA') }}
                            </button>
                        @endif

                        @if ($user->passkeys()->exists())
                            <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                    wire:click="resetPasskeys({{ $user->id }})"
                                    wire:confirm="{{ __('Delete all passkeys for this user?') }}">
                                {{ __('Reset Passkeys') }}
                            </button>
                        @endif

                        @if ($user->isBlocked())
                            <button class="cursor-pointer ms-6 text-sm text-green-500 underline focus:outline-none"
                                    wire:click="unblockUser({{ $user->id }})">
                                {{ __('Unblock') }}
                            </button>
                        @else
                            <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                    wire:click="confirmUserBlock({{ $user->id }})">
                                {{ __('Block') }}
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('No users found.') }}
                </div>
            @endforelse
        </div>
    </div>

    <!-- Block User Modal -->
    <x-dialog-modal wire:model.live="confirmingUserBlock">
        <x-slot name="title">
            {{ __('Block User') }}
        </x-slot>

        <x-slot name="content">
            {{ __('The user will be logged out everywhere and unable to sign in until unblocked.') }}

            <div class="mt-4">
                <x-label for="block-reason" value="{{ __('Reason (optional)') }}" />
                <x-input id="block-reason" type="text" class="mt-1 block w-full" wire:model="blockReason" />
                <x-input-error for="reason" class="mt-2" />
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingUserBlock')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="blockUser" wire:loading.attr="disabled">
                {{ __('Block User') }}
            </x-danger-button>
        </x-slot>
    </x-dialog-modal>
</div>

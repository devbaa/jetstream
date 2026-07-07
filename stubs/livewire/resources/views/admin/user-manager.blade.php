<div>
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
        <div class="flex items-center justify-between">
            <div class="w-full max-w-sm">
                <x-input type="text" class="block w-full" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search users...') }}" />
            </div>

            <div class="flex items-center">
                <x-action-message class="me-3" on="saved">
                    {{ __('Saved.') }}
                </x-action-message>

                <x-button wire:click="createUser" wire:loading.attr="disabled">
                    {{ __('New User') }}
                </x-button>
            </div>
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
                                    wire:click="resetTwoFactorAuthentication('{{ $user->id }}')"
                                    wire:confirm="{{ __('Reset two-factor authentication for this user?') }}">
                                {{ __('Reset 2FA') }}
                            </button>
                        @endif

                        @if ($user->passkeys()->exists())
                            <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                    wire:click="resetPasskeys('{{ $user->id }}')"
                                    wire:confirm="{{ __('Delete all passkeys for this user?') }}">
                                {{ __('Reset Passkeys') }}
                            </button>
                        @endif

                        @if ($user->isBlocked())
                            <button class="cursor-pointer ms-6 text-sm text-green-500 underline focus:outline-none"
                                    wire:click="unblockUser('{{ $user->id }}')">
                                {{ __('Unblock') }}
                            </button>
                        @else
                            <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                    wire:click="confirmUserBlock('{{ $user->id }}')">
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

    <!-- Create User Modal -->
    <x-dialog-modal wire:model.live="creatingUser">
        <x-slot name="title">
            {{ __('Create User') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <x-label for="new-user-name" value="{{ __('Name') }}" />
                    <x-input id="new-user-name" type="text" class="mt-1 block w-full" wire:model="createUserForm.name" />
                    <x-input-error for="name" class="mt-2" />
                </div>

                <div>
                    <x-label for="new-user-email" value="{{ __('Email') }}" />
                    <x-input id="new-user-email" type="email" class="mt-1 block w-full" wire:model="createUserForm.email" />
                    <x-input-error for="email" class="mt-2" />
                </div>

                <div>
                    <x-label for="new-user-password" value="{{ __('Password (optional)') }}" />
                    <x-input id="new-user-password" type="password" class="mt-1 block w-full" wire:model="createUserForm.password" autocomplete="new-password" />
                    <x-input-error for="password" class="mt-2" />

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Leave empty to email the user a password setup link instead.') }}
                    </p>
                </div>

                @if (Laravel\Jetstream\Features::hasDomainAdminFeatures())
                    <label class="flex items-center" for="new-user-domain-master">
                        <x-checkbox id="new-user-domain-master" wire:model="createUserForm.domain_master" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Make the user the domain master of their email domain') }}</span>
                    </label>
                    <x-input-error for="master_domains" class="mt-2" />
                @endif

                <label class="flex items-center" for="new-user-send-reset-mail">
                    <x-checkbox id="new-user-send-reset-mail" wire:model="createUserForm.send_reset_mail" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Email a password setup link when no password is set') }}</span>
                </label>
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('creatingUser', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button class="ms-3" wire:click="saveUser" wire:loading.attr="disabled">
                {{ __('Create') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

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

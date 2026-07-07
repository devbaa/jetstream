<div>
    <!-- Invite Customer -->
    <x-form-section submit="inviteCustomer">
        <x-slot name="title">
            {{ __('Invite Customer') }}
        </x-slot>

        <x-slot name="description">
            {{ __('Invite a new customer to your organization. They will receive an email with a link to accept the invitation and set up their customer account.') }}
        </x-slot>

        <x-slot name="form">
            <div class="col-span-6 sm:col-span-4">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" type="email" class="mt-1 block w-full" wire:model="inviteCustomerForm.email" />
                <x-input-error for="email" class="mt-2" />
            </div>
        </x-slot>

        <x-slot name="actions">
            <x-action-message class="me-3" on="saved">
                {{ __('Invited.') }}
            </x-action-message>

            <x-button>
                {{ __('Invite') }}
            </x-button>
        </x-slot>
    </x-form-section>

    @if ($this->pendingInvitations->isNotEmpty())
        <x-section-border />

        <!-- Pending Customer Invitations -->
        <div class="mt-10 sm:mt-0">
            <x-action-section>
                <x-slot name="title">
                    {{ __('Pending Customer Invitations') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('These people have been invited to become customers and have been sent an invitation email.') }}
                </x-slot>

                <x-slot name="content">
                    <div class="space-y-6">
                        @foreach ($this->pendingInvitations as $invitation)
                            <div class="flex items-center justify-between">
                                <div class="text-gray-600 dark:text-gray-400">{{ $invitation->email }}</div>

                                <div class="flex items-center">
                                    <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                                        wire:click="cancelCustomerInvitation('{{ $invitation->id }}')">
                                        {{ __('Cancel') }}
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-slot>
            </x-action-section>
        </div>
    @endif

    <x-section-border />

    <!-- Customer Account List -->
    <div class="mt-10 sm:mt-0">
        <x-action-section>
            <x-slot name="title">
                {{ __('Customer Accounts') }}
            </x-slot>

            <x-slot name="description">
                {{ __('All of the customer accounts that belong to this organization.') }}
            </x-slot>

            <x-slot name="content">
                <div class="space-y-6">
                    @forelse ($this->accounts as $account)
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-gray-900 dark:text-white">{{ $account->name }}</div>

                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ $account->owner->email }}

                                    @if ($account->users_count > 0)
                                        &middot; {{ trans_choice(':count additional member|:count additional members', $account->users_count, ['count' => $account->users_count]) }}
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center">
                                @if ($account->isFrozen())
                                    <span class="ms-6 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ __('Frozen') }}</span>
                                @endif

                                <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                                    wire:click="toggleAccountFreeze('{{ $account->id }}')">
                                    {{ $account->isFrozen() ? __('Unfreeze') : __('Freeze') }}
                                </button>

                                <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                                    wire:click="confirmAccountDeletion('{{ $account->id }}')">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('This organization does not have any customers yet.') }}
                        </div>
                    @endforelse
                </div>
            </x-slot>
        </x-action-section>
    </div>

    <!-- Delete Customer Account Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingAccountDeletion">
        <x-slot name="title">
            {{ __('Delete Customer Account') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to delete this customer account? All of its data will be permanently deleted.') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingAccountDeletion')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="deleteAccount" wire:loading.attr="disabled">
                {{ __('Delete') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>

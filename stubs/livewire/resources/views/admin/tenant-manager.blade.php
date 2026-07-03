<div>
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
        <div class="flex items-center justify-between">
            <div class="w-full max-w-sm">
                <x-input type="text" class="block w-full" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search tenants...') }}" />
            </div>

            <x-button class="ms-4" wire:click="createTenant" wire:loading.attr="disabled">
                {{ __('New Tenant') }}
            </x-button>
        </div>

        <div class="mt-6 space-y-6">
            @forelse ($this->tenants as $tenant)
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div>
                        <div class="text-gray-900 dark:text-white">{{ $tenant->name }}</div>

                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $tenant->slug }}
                            &middot; {{ __('Owner: :owner', ['owner' => $tenant->owner->email]) }}
                            &middot; {{ trans_choice(':count staff member|:count staff members', $tenant->users_count, ['count' => $tenant->users_count]) }}
                            &middot; {{ trans_choice(':count customer account|:count customer accounts', $tenant->customer_accounts_count, ['count' => $tenant->customer_accounts_count]) }}
                        </div>
                    </div>

                    <div class="flex items-center">
                        @if ($tenant->isFrozen())
                            <span class="ms-6 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ __('Frozen') }}</span>
                        @endif

                        <button class="cursor-pointer ms-6 text-sm {{ $tenant->allow_customer_registration ? 'text-green-500' : 'text-gray-400' }} underline focus:outline-none"
                                wire:click="toggleCustomerRegistration({{ $tenant->id }})">
                            {{ $tenant->allow_customer_registration ? __('Self-registration on') : __('Self-registration off') }}
                        </button>

                        <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                wire:click="toggleTenantFreeze({{ $tenant->id }})"
                                wire:confirm="{{ $tenant->isFrozen() ? __('Unfreeze this tenant?') : __('Freeze this tenant? Its staff and customers will lose access.') }}">
                            {{ $tenant->isFrozen() ? __('Unfreeze') : __('Freeze') }}
                        </button>

                        <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                wire:click="confirmTenantDeletion({{ $tenant->id }})">
                            {{ __('Delete') }}
                        </button>
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('No tenants found.') }}
                </div>
            @endforelse
        </div>
    </div>

    <!-- Create Tenant Modal -->
    <x-dialog-modal wire:model.live="creatingTenant">
        <x-slot name="title">
            {{ __('Create Tenant') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <x-label for="tenant-name" value="{{ __('Name') }}" />
                    <x-input id="tenant-name" type="text" class="mt-1 block w-full" wire:model="createTenantForm.name" />
                    <x-input-error for="name" class="mt-2" />
                </div>

                <div>
                    <x-label for="tenant-owner-email" value="{{ __('Owner Email') }}" />
                    <x-input id="tenant-owner-email" type="email" class="mt-1 block w-full" wire:model="createTenantForm.owner_email" />
                    <x-input-error for="owner_email" class="mt-2" />
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('creatingTenant', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button class="ms-3" wire:click="saveTenant" wire:loading.attr="disabled">
                {{ __('Create') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <!-- Delete Tenant Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingTenantDeletion">
        <x-slot name="title">
            {{ __('Delete Tenant') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to delete this tenant? All of its teams, roles, customers, and data will be permanently deleted.') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingTenantDeletion')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="deleteTenant" wire:loading.attr="disabled">
                {{ __('Delete') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>

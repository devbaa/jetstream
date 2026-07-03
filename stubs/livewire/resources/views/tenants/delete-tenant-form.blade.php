<x-action-section>
    <x-slot name="title">
        {{ __('Delete Organization') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete this organization.') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
            {{ __('Once an organization is deleted, all of its teams, roles, customers, and data will be permanently deleted. Before deleting this organization, please download any data or information that you wish to retain.') }}
        </div>

        <div class="mt-5">
            <x-danger-button wire:click="$toggle('confirmingTenantDeletion')" wire:loading.attr="disabled">
                {{ __('Delete Organization') }}
            </x-danger-button>
        </div>

        <!-- Delete Tenant Confirmation Modal -->
        <x-confirmation-modal wire:model.live="confirmingTenantDeletion">
            <x-slot name="title">
                {{ __('Delete Organization') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Are you sure you want to delete this organization? Once an organization is deleted, all of its teams, customers, and data will be permanently deleted.') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingTenantDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" wire:click="deleteTenant" wire:loading.attr="disabled">
                    {{ __('Delete Organization') }}
                </x-danger-button>
            </x-slot>
        </x-confirmation-modal>
    </x-slot>
</x-action-section>

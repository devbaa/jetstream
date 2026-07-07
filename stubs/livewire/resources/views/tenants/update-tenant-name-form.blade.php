<x-form-section submit="updateTenantName">
    <x-slot name="title">
        {{ __('Organization Name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The organization\'s name and owner information.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Tenant Owner Information -->
        <div class="col-span-6">
            <x-label value="{{ __('Organization Owner') }}" />

            <div class="flex items-center mt-2">
                <img class="size-12 rounded-full object-cover" src="{{ $tenant->owner->profile_photo_url }}" alt="{{ $tenant->owner->name }}">

                <div class="ms-4 leading-tight">
                    <div class="text-gray-900 dark:text-white">{{ $tenant->owner->name }}</div>
                    <div class="text-gray-700 dark:text-gray-300 text-sm">{{ $tenant->owner->email }}</div>
                </div>
            </div>
        </div>

        <!-- Tenant Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Organization Name') }}" />

            <x-input id="name"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $tenant)" />

            <x-input-error for="name" class="mt-2" />
        </div>
    </x-slot>

    @if (Gate::check('update', $tenant))
        <x-slot name="actions">
            <x-action-message class="me-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>

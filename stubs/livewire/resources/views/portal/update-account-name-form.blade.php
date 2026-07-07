<x-form-section submit="updateAccountName">
    <x-slot name="title">
        {{ __('Account Name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The customer account\'s name and owner information.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Account Owner Information -->
        <div class="col-span-6">
            <x-label value="{{ __('Account Owner') }}" />

            <div class="flex items-center mt-2">
                <img class="size-12 rounded-full object-cover" src="{{ $account->owner->profile_photo_url }}" alt="{{ $account->owner->name }}">

                <div class="ms-4 leading-tight">
                    <div class="text-gray-900 dark:text-white">{{ $account->owner->name }}</div>
                    <div class="text-gray-700 dark:text-gray-300 text-sm">{{ $account->owner->email }}</div>
                </div>
            </div>
        </div>

        <!-- Account Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Account Name') }}" />

            <x-input id="name"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $account)" />

            <x-input-error for="name" class="mt-2" />
        </div>
    </x-slot>

    @if (Gate::check('update', $account))
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

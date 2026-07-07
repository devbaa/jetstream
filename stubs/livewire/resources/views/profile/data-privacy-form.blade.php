<x-action-section>
    <x-slot name="title">
        {{ __('Data & Privacy') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Download a copy of your personal data or request the deletion of your account (GDPR, CCPA, KVKK).') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
            {{ __('You may download a copy of the personal data we hold about you at any time. You may also request the permanent deletion of your account: after a grace period during which you can cancel the request, your account and all of its data will be permanently erased.') }}
        </div>

        <div class="mt-5 flex items-center">
            <x-secondary-button wire:click="downloadPersonalData" wire:loading.attr="disabled">
                {{ __('Download Personal Data') }}
            </x-secondary-button>

            @if ($this->pendingDeletionRequest)
                <x-button class="ms-3" wire:click="cancelAccountDeletion" wire:loading.attr="disabled">
                    {{ __('Cancel Deletion Request') }}
                </x-button>
            @else
                <x-confirms-password wire:then="confirmDeletionRequest">
                    <x-danger-button class="ms-3" wire:loading.attr="disabled">
                        {{ __('Request Account Deletion') }}
                    </x-danger-button>
                </x-confirms-password>
            @endif

            <x-action-message class="ms-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>

        @if ($this->pendingDeletionRequest)
            <div class="mt-3 max-w-xl text-sm text-red-600 dark:text-red-400">
                {{ __('Your account is scheduled for deletion on :date. You may cancel the request until then.', ['date' => $this->pendingDeletionRequest->process_after?->toFormattedDateString()]) }}
            </div>
        @endif

        <!-- Deletion Request Confirmation Modal -->
        <x-dialog-modal wire:model.live="confirmingDeletionRequest">
            <x-slot name="title">
                {{ __('Request Account Deletion') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Are you sure you want to request the deletion of your account? Once the grace period has elapsed, your account and all of its data will be permanently erased. Before then, you may cancel the request at any time.') }}

                <div class="mt-4">
                    <x-input type="text" class="mt-1 block w-3/4"
                                placeholder="{{ __('Reason (optional)') }}"
                                wire:model="reason"
                                wire:keydown.enter="requestAccountDeletion" />

                    <x-input-error for="reason" class="mt-2" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingDeletionRequest')" wire:loading.attr="disabled">
                    {{ __('Never Mind') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" wire:click="requestAccountDeletion" wire:loading.attr="disabled">
                    {{ __('Request Account Deletion') }}
                </x-danger-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>

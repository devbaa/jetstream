<div>
    @if (Gate::check('addMember', $account))
        <x-section-border />

        <!-- Add Account Member -->
        <div class="mt-10 sm:mt-0">
            <x-form-section submit="addMember">
                <x-slot name="title">
                    {{ __('Add Account Member') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('Invite another person to share this customer account. They will receive an email with a link to accept the invitation.') }}
                </x-slot>

                <x-slot name="form">
                    <div class="col-span-6 sm:col-span-4">
                        <x-label for="email" value="{{ __('Email') }}" />
                        <x-input id="email" type="email" class="mt-1 block w-full" wire:model="addMemberForm.email" />
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
        </div>
    @endif

    @if ($account->customerInvitations->isNotEmpty() && Gate::check('addMember', $account))
        <x-section-border />

        <!-- Pending Member Invitations -->
        <div class="mt-10 sm:mt-0">
            <x-action-section>
                <x-slot name="title">
                    {{ __('Pending Invitations') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('These people have been invited to this customer account and have been sent an invitation email.') }}
                </x-slot>

                <x-slot name="content">
                    <div class="space-y-6">
                        @foreach ($account->customerInvitations as $invitation)
                            <div class="flex items-center justify-between">
                                <div class="text-gray-600 dark:text-gray-400">{{ $invitation->email }}</div>

                                <div class="flex items-center">
                                    <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                                        wire:click="cancelInvitation({{ $invitation->id }})">
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

    @if ($account->users->isNotEmpty())
        <x-section-border />

        <!-- Manage Account Members -->
        <div class="mt-10 sm:mt-0">
            <x-action-section>
                <x-slot name="title">
                    {{ __('Account Members') }}
                </x-slot>

                <x-slot name="description">
                    {{ __('All of the people that share this customer account.') }}
                </x-slot>

                <x-slot name="content">
                    <div class="space-y-6">
                        @foreach ($account->users->sortBy('name') as $user)
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <img class="size-8 rounded-full object-cover" src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}">
                                    <div class="ms-4 dark:text-white">{{ $user->name }}</div>
                                </div>

                                <div class="flex items-center">
                                    <!-- Leave Account -->
                                    @if ($this->user->id === $user->id)
                                        <button class="cursor-pointer ms-6 text-sm text-red-500" wire:click="$toggle('confirmingLeavingAccount')">
                                            {{ __('Leave') }}
                                        </button>

                                    <!-- Remove Account Member -->
                                    @elseif (Gate::check('removeMember', $account))
                                        <button class="cursor-pointer ms-6 text-sm text-red-500" wire:click="confirmMemberRemoval('{{ $user->id }}')">
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-slot>
            </x-action-section>
        </div>
    @endif

    <!-- Leave Account Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingLeavingAccount">
        <x-slot name="title">
            {{ __('Leave Customer Account') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to leave this customer account?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingLeavingAccount')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="leaveAccount" wire:loading.attr="disabled">
                {{ __('Leave') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>

    <!-- Remove Account Member Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingMemberRemoval">
        <x-slot name="title">
            {{ __('Remove Account Member') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to remove this person from the customer account?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingMemberRemoval')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="removeMember" wire:loading.attr="disabled">
                {{ __('Remove') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>

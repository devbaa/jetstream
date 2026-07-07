<div>
    <!-- Domain Claims -->
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">{{ __('Your Domains') }}</h3>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Prove authority over a domain to manage its verified users. Publish your verification token as a DNS TXT record or as a meta tag on the domain\'s home page, then check the verification. The most recent successful verification holds the domain admin flag.') }}
                </p>
            </div>

            <x-action-message class="ms-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>

        <div class="mt-6">
            @if (Laravel\Jetstream\Features::allowsMultipleDomains())
                <div class="flex items-end">
                    <div class="w-full max-w-sm">
                        <x-label for="claim-domain" value="{{ __('Domain') }}" />
                        <x-input id="claim-domain" type="text" class="mt-1 block w-full" wire:model="domainForm.domain" placeholder="example.com" />
                    </div>

                    <x-button class="ms-4" wire:click="startClaim" wire:loading.attr="disabled">
                        {{ __('Claim Domain') }}
                    </x-button>
                </div>
            @else
                <x-button wire:click="startClaim" wire:loading.attr="disabled">
                    {{ __('Claim :domain', ['domain' => $this->user->emailDomain()]) }}
                </x-button>
            @endif

            <x-input-error for="domain" class="mt-2" />
            <x-input-error for="verification" class="mt-2" />
        </div>

        <div class="mt-6 space-y-6">
            @forelse ($this->claims as $claim)
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div class="flex items-center justify-between">
                        <div class="text-gray-900 dark:text-white">
                            {{ $claim->domain }}

                            @if ($claim->isActive())
                                <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">{{ __('Active') }}</span>
                            @elseif ($claim->isVerified())
                                <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">{{ __('Superseded') }}</span>
                            @else
                                <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">{{ __('Pending') }}</span>
                            @endif
                        </div>

                        <div class="flex items-center">
                            @if ($claim->isActive())
                                <button class="cursor-pointer ms-6 text-sm text-indigo-500 underline focus:outline-none"
                                        wire:click="manageClaim('{{ $claim->id }}')">
                                    {{ __('Manage Users') }}
                                </button>
                            @else
                                <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                        wire:click="checkClaim('{{ $claim->id }}')"
                                        wire:loading.attr="disabled">
                                    {{ __('Check Verification') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    @unless ($claim->isActive())
                        <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 space-y-2">
                            <div>
                                {{ __('DNS TXT record:') }}
                                <code class="block mt-1 p-2 bg-gray-100 dark:bg-gray-900 rounded text-xs break-all">{{ $claim->recordValue() }}</code>
                            </div>

                            <div>
                                {{ __('Or meta tag on the home page:') }}
                                <code class="block mt-1 p-2 bg-gray-100 dark:bg-gray-900 rounded text-xs break-all">{{ $claim->metaTag() }}</code>
                            </div>
                        </div>
                    @endunless
                </div>
            @empty
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('You have not claimed any domains yet.') }}
                </div>
            @endforelse
        </div>
    </div>

    @if ($this->managedClaim)
        <!-- Domain Members -->
        <div class="mt-10 bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    {{ __('Verified users of :domain', ['domain' => $this->managedClaim->domain]) }}
                </h3>

                <button class="cursor-pointer text-sm text-gray-400 underline focus:outline-none" wire:click="stopManaging">
                    {{ __('Close') }}
                </button>
            </div>

            <div class="mt-6 space-y-6">
                @forelse ($this->members as $member)
                    <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-4">
                        <div>
                            <div class="text-gray-900 dark:text-white">
                                {{ $member->fullName() }}

                                @if ($member->isBlocked())
                                    <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">{{ __('Blocked') }}</span>
                                @endif
                            </div>

                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ $member->email }}
                            </div>
                        </div>

                        <div class="flex items-center">
                            @if ($member->isBlocked())
                                <button class="cursor-pointer ms-6 text-sm text-green-500 underline focus:outline-none"
                                        wire:click="unblockMember('{{ $member->id }}')">
                                    {{ __('Unblock') }}
                                </button>
                            @else
                                <button class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                        wire:click="confirmMemberBlock('{{ $member->id }}')">
                                    {{ __('Block') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No verified users share this domain yet.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Activity -->
        @if ($this->activities->isNotEmpty())
            <div class="mt-10 bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">{{ __('Recent Activity') }}</h3>

                <div class="mt-6 space-y-4">
                    @foreach ($this->activities as $activity)
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <span class="text-gray-900 dark:text-white">{{ $activity->action }}</span>

                            @if ($activity->subject)
                                &middot; {{ $activity->subject->email }}
                            @endif

                            &middot; {{ $activity->created_at?->diffForHumans() }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <!-- Block Member Modal -->
    <x-dialog-modal wire:model.live="confirmingMemberBlock">
        <x-slot name="title">
            {{ __('Block Domain Member') }}
        </x-slot>

        <x-slot name="content">
            {{ __('The user will be logged out everywhere and unable to sign in until unblocked.') }}

            <div class="mt-4">
                <x-label for="member-block-reason" value="{{ __('Reason (optional)') }}" />
                <x-input id="member-block-reason" type="text" class="mt-1 block w-full" wire:model="blockReason" />
                <x-input-error for="reason" class="mt-2" />
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingMemberBlock')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="blockMember" wire:loading.attr="disabled">
                {{ __('Block User') }}
            </x-danger-button>
        </x-slot>
    </x-dialog-modal>
</div>

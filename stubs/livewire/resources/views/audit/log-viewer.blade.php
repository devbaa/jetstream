<x-action-section>
    <x-slot name="title">
        {{ __('Audit Log') }}
    </x-slot>

    <x-slot name="description">
        {{ __('A full change log of everything that happened, including who did it, from where, and with which device.') }}
    </x-slot>

    <x-slot name="content">
        @if (count($this->logs) === 0)
            <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
                {{ __('No activity has been recorded yet.') }}
            </div>
        @else
            <div class="space-y-6">
                @foreach ($this->logs as $log)
                    <div class="flex items-start">
                        <div class="shrink-0 mt-1">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6 text-gray-500">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>

                        <div class="ms-3">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                <span class="font-semibold">{{ $log->event }}</span>

                                @if ($log->auditable_type)
                                    — {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                @endif

                                @if ($log->user)
                                    {{ __('by') }} {{ $log->user->name }}
                                @endif
                            </div>

                            <div class="text-xs text-gray-500">
                                {{ $log->created_at?->toDayDateTimeString() }}

                                @if ($log->ip_address)
                                    · {{ $log->ip_address }}
                                @endif

                                @if ($log->user_agent)
                                    · {{ \Illuminate\Support\Str::limit($log->user_agent, 60) }}
                                @endif
                            </div>

                            @if ($log->old_values || $log->new_values)
                                <div class="mt-1 text-xs text-gray-500 font-mono break-all">
                                    @if ($log->old_values)
                                        <span class="text-red-500">- {{ json_encode($log->old_values) }}</span><br>
                                    @endif

                                    @if ($log->new_values)
                                        <span class="text-green-600">+ {{ json_encode($log->new_values) }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($this->hasMore)
                <div class="mt-5">
                    <x-secondary-button wire:click="loadMore" wire:loading.attr="disabled">
                        {{ __('Load More') }}
                    </x-secondary-button>
                </div>
            @endif
        @endif
    </x-slot>
</x-action-section>

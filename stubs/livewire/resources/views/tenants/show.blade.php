<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Organization Settings') }}
        </h2>
    </x-slot>

    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            <div class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Learn how staff, roles, teams, customers, and freezing work.') }}
                <a href="{{ route('help.tenant') }}" class="underline text-gray-900 dark:text-gray-100">{{ __('Visit Organization Help') }}</a>.
            </div>

            @livewire('tenants.update-tenant-name-form', ['tenant' => $tenant])

            @livewire('tenants.tenant-staff-manager', ['tenant' => $tenant])

            @if (Gate::check('manageRoles', $tenant))
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('tenants.role-manager', ['tenant' => $tenant])
                </div>
            @endif

            @if (Gate::check('update', $tenant))
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('audit-log-viewer', ['tenant' => $tenant])
                </div>
            @endif

            @if (Gate::check('delete', $tenant))
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('tenants.delete-tenant-form', ['tenant' => $tenant])
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Customer Portal') }}
        </h2>
    </x-slot>

    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            @if ($account)
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                    <h1 class="text-2xl font-medium text-gray-900 dark:text-white">
                        {{ __('Welcome to the :tenant portal!', ['tenant' => $account->tenant->name]) }}
                    </h1>

                    <p class="mt-2 text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('You are signed in to the :account customer account.', ['account' => $account->name]) }}
                    </p>

                    <div class="mt-6">
                        <a href="{{ route('portal.account.show') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                            {{ __('Manage this customer account') }}
                        </a>
                    </div>
                </div>
            @endif

            @if ($user->allCustomerAccounts()->count() > ($account ? 1 : 0))
                <div class="mt-8 bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                        {{ $account ? __('Switch Customer Account') : __('Choose a Customer Account') }}
                    </h2>

                    <div class="mt-4 space-y-4">
                        @foreach ($user->allCustomerAccounts() as $switchableAccount)
                            @if (! $account || $switchableAccount->id !== $account->id)
                                <form method="POST" action="{{ route('current-customer-account.update') }}">
                                    @csrf
                                    @method('PUT')

                                    <input type="hidden" name="customer_account_id" value="{{ $switchableAccount->id }}">

                                    <button type="submit" class="flex items-center justify-between w-full px-4 py-3 border border-gray-200 dark:border-gray-700 rounded-lg text-start hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <div>
                                            <div class="text-gray-900 dark:text-white">{{ $switchableAccount->name }}</div>
                                            <div class="text-sm text-gray-600 dark:text-gray-400">{{ $switchableAccount->tenant->name }}</div>
                                        </div>

                                        <svg class="size-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if (! $account && $user->allCustomerAccounts()->isEmpty())
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                    <p class="text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('You are not a customer of any organization yet.') }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

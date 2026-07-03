<x-guest-layout>
    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                <h1 class="text-2xl font-medium text-gray-900 dark:text-white">
                    {{ __('Become a customer of :tenant', ['tenant' => $tenant->name]) }}
                </h1>

                @if ($alreadyCustomer)
                    <p class="mt-2 text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('You are already a customer of this organization.') }}
                    </p>

                    <div class="mt-6">
                        <a href="{{ route('portal.show') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                            {{ __('Go to the customer portal') }}
                        </a>
                    </div>
                @elseif ($user)
                    <p class="mt-2 text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('Confirm below to create your customer account with :tenant.', ['tenant' => $tenant->name]) }}
                    </p>

                    <form method="POST" action="{{ route('portal.register.store', ['slug' => $tenant->slug]) }}" class="mt-6">
                        @csrf

                        <!-- Honeypot: invisible to humans, tempting to bots. Leave empty. -->
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input id="website" type="text" name="website" value="" tabindex="-1" autocomplete="off" />
                        </div>

                        <x-button>
                            {{ __('Create Customer Account') }}
                        </x-button>
                    </form>
                @else
                    <p class="mt-2 text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('Please sign in or create an account to become a customer of :tenant.', ['tenant' => $tenant->name]) }}
                    </p>

                    <div class="mt-6 flex items-center gap-4">
                        <a href="{{ route('login') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                            {{ __('Log in') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                                {{ __('Register') }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-guest-layout>

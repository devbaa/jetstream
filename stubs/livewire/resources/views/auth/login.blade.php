<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />

        @session('status')
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ $value }}
            </div>
        @endsession

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username webauthn" />
            </div>

            <div class="mt-4">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="flex items-center">
                    <x-checkbox id="remember_me" name="remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                @if (Route::has('password.request'))
                    <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif

                <x-button class="ms-4">
                    {{ __('Log in') }}
                </x-button>
            </div>
        </form>

        @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::passkeys()))
            <div class="mt-4">
                <x-secondary-button type="button" class="w-full justify-center" onclick="signInWithPasskey()">
                    {{ __('Sign in with a passkey') }}
                </x-secondary-button>

                <p id="passkey-login-error" class="mt-2 text-sm text-red-600 dark:text-red-400 hidden"></p>
            </div>

            <script>
                function signInWithPasskey() {
                    const error = document.getElementById('passkey-login-error');

                    error.classList.add('hidden');

                    if (! window.Passkeys || ! window.Passkeys.isSupported()) {
                        error.textContent = @js(__('Your browser does not support passkeys.'));
                        error.classList.remove('hidden');

                        return;
                    }

                    window.Passkeys.verify()
                        .then((response) => window.location.href = response.redirect ?? @js(config('fortify.home', '/dashboard')))
                        .catch((failure) => {
                            error.textContent = failure?.message ?? @js(__('The passkey could not be verified.'));
                            error.classList.remove('hidden');
                        });
                }

                document.addEventListener('DOMContentLoaded', () => {
                    if (window.Passkeys && window.Passkeys.isSupported()) {
                        window.Passkeys.autofill()
                            .then((response) => {
                                if (response) {
                                    window.location.href = response.redirect ?? @js(config('fortify.home', '/dashboard'));
                                }
                            })
                            .catch(() => {});
                    }
                });
            </script>
        @endif
    </x-authentication-card>
</x-guest-layout>

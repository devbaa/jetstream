<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Account Help') }}
        </h2>
    </x-slot>

    <div>
        <div class="max-w-4xl mx-auto py-10 sm:px-6 lg:px-8 space-y-6">
            <p class="px-4 sm:px-0 text-gray-600 dark:text-gray-400">
                {{ __('Everything you need to keep your account secure and to stay in control of your data. Manage all of these from your') }}
                <a href="{{ route('profile.show') }}" class="underline text-gray-900 dark:text-gray-100">{{ __('profile page') }}</a>.
            </p>

            <!-- Passwords & Sign-in -->
            <x-help-topic :title="__('Signing in & passwords')" icon="key">
                <p>{{ __('You sign in with your email address and password. You can change your password at any time from your profile under "Update Password".') }}</p>
                <ul>
                    <li>{{ __('Forgot your password? On the login screen choose "Forgot your password?" and we will email you a reset link.') }}</li>
                    <li>{{ __('Lost access to your main inbox too? See "Account recovery" below.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Two-Factor Authentication -->
            <x-help-topic :title="__('Two-factor authentication (2FA)')" icon="shield">
                <p>{{ __('Two-factor authentication adds a second step to sign-in using an authenticator app (such as Google Authenticator, 1Password, or Authy).') }}</p>
                <ol>
                    <li>{{ __('On your profile, open "Two Factor Authentication" and select "Enable".') }}</li>
                    <li>{{ __('Scan the QR code with your authenticator app and confirm the 6-digit code.') }}</li>
                    <li>{{ __('Save your recovery codes somewhere safe — each one lets you sign in once if you lose your phone.') }}</li>
                </ol>
                <p class="font-medium">{{ __('If you lose your device:') }}</p>
                <ul>
                    <li>{{ __('Use one of your saved recovery codes at the challenge screen.') }}</li>
                    <li>{{ __('If you also lost your recovery codes, sign in with a passkey (below), or ask an administrator to reset your 2FA.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Passkeys -->
            <x-help-topic :title="__('Passkeys')" icon="finger-print">
                <p>{{ __('A passkey lets you sign in with your device\'s fingerprint, face, or PIN instead of a password — it cannot be phished or reused.') }}</p>
                <ol>
                    <li>{{ __('On your profile, open "Passkeys" and select "Register a New Passkey".') }}</li>
                    <li>{{ __('Give it a name (for example "MacBook" or "iPhone") and follow your device\'s prompt.') }}</li>
                    <li>{{ __('Next time, choose "Sign in with a passkey" on the login screen — many browsers will even offer it automatically.') }}</li>
                </ol>
                <p class="font-medium">{{ __('Resetting passkeys:') }}</p>
                <ul>
                    <li>{{ __('You can remove any passkey from your profile at any time and register a new one.') }}</li>
                    <li>{{ __('Lost every device that held a passkey? An administrator can clear your passkeys so you can start again with a password or a fresh passkey.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Account Recovery: recovery email + phone -->
            <x-help-topic :title="__('Account recovery — recovery email & phone')" icon="lifebuoy">
                <p>{{ __('Recovery channels help you get back in if you lose access to your main email inbox.') }}</p>

                <p class="font-medium">{{ __('Secondary (recovery) email') }}</p>
                <ol>
                    <li>{{ __('On your profile, open "Account Recovery" and enter a recovery email address that is different from your main one.') }}</li>
                    <li>{{ __('We send a verification link to that address — open it to confirm the mailbox is yours. Only verified recovery emails can be used.') }}</li>
                    <li>{{ __('If you are ever locked out, visit the "Account Recovery" link on the login page, enter your recovery email, and we will send a password reset link there.') }}</li>
                </ol>

                <p class="font-medium">{{ __('Phone number') }}</p>
                <ol>
                    <li>{{ __('In "Account Recovery", pick your country and enter your phone number — we store it in the standard international format.') }}</li>
                    <li>{{ __('If phone verification is switched on, select "Send a verification code" and enter the code we text you.') }}</li>
                    <li>{{ __('If phone verification is not active yet, your number is still saved (shown as unverified) so your team can reach you.') }}</li>
                </ol>
            </x-help-topic>

            <!-- Email verification -->
            <x-help-topic :title="__('Verifying your email')" icon="envelope">
                <p>{{ __('We may ask you to verify your primary email address so we know we can reach you and so sensitive actions stay protected.') }}</p>
                <ul>
                    <li>{{ __('Look for the "unverified" note next to your email on your profile and choose "Click here to re-send the verification email".') }}</li>
                    <li>{{ __('Changing your email address resets its verification — you will get a fresh confirmation link.') }}</li>
                    <li>{{ __('Your recovery email is verified separately, with its own link (see "Account recovery").') }}</li>
                </ul>
            </x-help-topic>

            <!-- Data & Privacy: GDPR export -->
            <x-help-topic :title="__('Your data & privacy (GDPR / CCPA / KVKK)')" icon="document">
                <p>{{ __('You are always in control of the personal data we hold about you.') }}</p>
                <p class="font-medium">{{ __('Download a copy of your data') }}</p>
                <ul>
                    <li>{{ __('On your profile, open "Data & Privacy" and choose "Download Personal Data".') }}</li>
                    <li>{{ __('You will get a machine-readable JSON file with your profile, teams, organizations, customer accounts, and recent activity.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Deleting your account -->
            <x-help-topic :title="__('Deleting your account')" icon="trash">
                <p>{{ __('You can ask us to permanently erase your account and everything tied to it. Deletion happens in clear steps so you can change your mind.') }}</p>
                <ol>
                    <li>{{ __('On your profile, open "Data & Privacy" and choose "Request Account Deletion". Confirm your password and, optionally, tell us why.') }}</li>
                    <li>{{ __('Your request enters a grace period (typically 30 days). During this time nothing is lost and you can cancel from the same screen.') }}</li>
                    <li>{{ __('When the grace period ends, your account is deactivated and scheduled for permanent erasure.') }}</li>
                    <li>{{ __('After a final retention window, everything you own — profile, teams, organizations, customer accounts, tokens, passkeys, and sessions — is permanently and irreversibly deleted.') }}</li>
                </ol>
                <p>{{ __('Prefer to leave immediately? The "Delete Account" section removes your account right away, subject to the same permanent-erasure schedule.') }}</p>
            </x-help-topic>

            <!-- Activity log -->
            <x-help-topic :title="__('Your activity & security log')" icon="clock">
                <p>{{ __('We keep a record of important actions — sign-ins, changes, and more — including the approximate location (IP address) and device used, so you can spot anything unexpected.') }}</p>
                <ul>
                    <li>{{ __('Manage your active browser sessions from "Browser Sessions" on your profile, and log out other devices if something looks wrong.') }}</li>
                    <li>{{ __('Your downloadable data export includes your recent activity history.') }}</li>
                </ul>
            </x-help-topic>
        </div>
    </div>
</x-app-layout>

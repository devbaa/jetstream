@component('mail::message')
{{ __('An account has been created for you. Choose a password using the button below to start signing in.') }}

@component('mail::button', ['url' => $resetUrl])
{{ __('Set Password') }}
@endcomponent

{{ __('If you were not expecting this account, you can ignore this email.') }}
@endcomponent

@component('mail::message')
{{ __('You are receiving this email because an account recovery was requested using this verified recovery email address.') }}

@component('mail::button', ['url' => $resetUrl])
{{ __('Reset Password') }}
@endcomponent

{{ __('If you did not request an account recovery, no further action is required.') }}
@endcomponent

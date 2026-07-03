@component('mail::message')
{{ __('This address was added as the account recovery email for :name. Please verify it by clicking the button below:', ['name' => $user->name]) }}

@component('mail::button', ['url' => $verifyUrl])
{{ __('Verify Recovery Email') }}
@endcomponent

{{ __('Once verified, this address can receive password reset links for the account.') }}

{{ __('If you did not add this address as a recovery email, you may discard this email.') }}
@endcomponent

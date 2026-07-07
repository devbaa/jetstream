@component('mail::message')
@if ($invitation->customer_account_id)
{{ __('You have been invited to join the :account customer account at :tenant!', ['account' => $invitation->customerAccount->name, 'tenant' => $invitation->tenant->name]) }}
@else
{{ __('You have been invited to become a customer of :tenant!', ['tenant' => $invitation->tenant->name]) }}
@endif

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the invitation:') }}

@component('mail::button', ['url' => route('register')])
{{ __('Create Account') }}
@endcomponent

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}

@else
{{ __('You may accept this invitation by clicking the button below:') }}
@endif


@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept Invitation') }}
@endcomponent

{{ __('If you did not expect to receive this invitation, you may discard this email.') }}
@endcomponent

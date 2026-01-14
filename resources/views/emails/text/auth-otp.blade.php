{{ $title }}

OTP: {{ $otp }}
{{ __('notifications.email.meta.expires') }}: {{ $expires }}

{{ $footer ?? __('notifications.email.footer') }}

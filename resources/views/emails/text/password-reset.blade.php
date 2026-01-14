{{ $title }}

{{ $bodyText }}

{{ __('notifications.email.meta.expires') }}: {{ $expiresLabel }}
{{ __('notifications.email.password_reset.action') }}: {{ $actionUrl }}

{{ $footer ?? __('notifications.email.footer') }}

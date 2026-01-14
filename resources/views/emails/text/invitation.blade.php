{{ $title }}

{{ $bodyText }}

{{ __('notifications.email.meta.expires') }}: {{ $expiresLabel }}
{{ __('notifications.email.invitation.action') }}: {{ $actionUrl }}

{{ $footer ?? __('notifications.email.footer') }}

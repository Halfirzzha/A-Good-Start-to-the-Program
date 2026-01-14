{{ $title }}

{{ $bodyText }}

{{ __('notifications.email.meta.event') }}: {{ $event }}
{{ __('notifications.email.meta.request_id') }}: {{ $requestId }}
{{ __('notifications.email.meta.ip') }}: {{ $ipAddress }}

{{ $footer ?? __('notifications.email.footer') }}

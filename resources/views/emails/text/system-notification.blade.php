{{ $title }}

{{ $bodyText }}

{{ __('notifications.email.meta.category') }}: {{ $category }}
{{ __('notifications.email.meta.priority') }}: {{ $priority }}

{{ $footer ?? __('notifications.email.footer') }}

@php
    $accentColor = $accentColor ?? '#F59E0B';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:Arial, sans-serif;">
    @if (! empty($preheader))
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; font-size:1px; line-height:1px;">
            {{ $preheader }}
        </div>
    @endif
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 10px 25px rgba(15, 23, 42, 0.08);">
                    <tr>
                        <td style="padding:20px 28px; background-color:#0f172a; color:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <div style="font-size:12px; letter-spacing:0.6px; text-transform:uppercase; opacity:0.8;">
                                            {{ $appName }}
                                        </div>
                                        <h1 style="margin:6px 0 0; font-size:20px; font-weight:600;">
                                            {{ $title }}
                                        </h1>
                                    </td>
                                    @if (! empty($logoUrl))
                                        <td style="vertical-align:middle; text-align:right;">
                                            <img src="{{ $logoUrl }}" alt="{{ $appName }} logo" style="height:36px; max-width:160px; display:inline-block;">
                                        </td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:4px; background-color:{{ $accentColor }};"></td>
                    </tr>
                    @if (! empty($preheader))
                        <tr>
                            <td style="padding:14px 28px; background-color:#f1f5f9; color:#475569; font-size:12px;">
                                {{ $preheader }}
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:24px 28px; color:#0f172a; font-size:14px; line-height:1.6;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    @if (! empty($meta) && is_array($meta))
                        <tr>
                            <td style="padding:0 28px 24px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e2e8f0; border-radius:10px;">
                                    @foreach ($meta as $label => $value)
                                        <tr>
                                            <td style="padding:10px 14px; font-size:12px; color:#64748b; width:35%; border-bottom:1px solid #e2e8f0;">
                                                {{ $label }}
                                            </td>
                                            <td style="padding:10px 14px; font-size:12px; color:#0f172a; border-bottom:1px solid #e2e8f0;">
                                                {{ $value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif
                    @if (! empty($actionUrl) && ! empty($actionLabel))
                        <tr>
                            <td style="padding:0 28px 24px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block; background-color:{{ $accentColor }}; color:#0f172a; text-decoration:none; padding:10px 16px; border-radius:8px; font-size:13px; font-weight:600;">
                                    {{ $actionLabel }}
                                </a>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:16px 28px; background-color:#f1f5f9; color:#475569; font-size:12px;">
                            {{ $footer ?? __('notifications.email.footer') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

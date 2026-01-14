@php
    $meta = [
        __('notifications.email.meta.username') => $username,
        __('notifications.email.meta.expires') => $expires,
    ];
@endphp

@extends('emails.layout', [
    'title' => $title,
    'appName' => $appName,
    'preheader' => $preheader,
    'bodyHtml' => $bodyHtml,
    'meta' => $meta,
    'actionUrl' => $actionUrl,
    'actionLabel' => $actionLabel,
    'footer' => $footer,
])

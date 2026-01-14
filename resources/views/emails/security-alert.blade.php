@php
    $meta = [
        __('notifications.email.meta.event') => $event,
        __('notifications.email.meta.request_id') => $requestId,
        __('notifications.email.meta.ip') => $ipAddress,
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

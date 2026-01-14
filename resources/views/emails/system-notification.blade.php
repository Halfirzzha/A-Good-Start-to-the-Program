@php
    $meta = [
        __('notifications.email.meta.category') => $category,
        __('notifications.email.meta.priority') => $priority,
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

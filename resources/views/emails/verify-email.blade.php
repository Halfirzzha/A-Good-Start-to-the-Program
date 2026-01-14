@php
    $meta = [
        __('notifications.email.meta.email') => $email,
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

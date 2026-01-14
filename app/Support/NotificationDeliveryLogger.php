<?php

namespace App\Support;

use App\Models\NotificationDelivery;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NotificationDeliveryLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(
        mixed $notifiable,
        ?Notification $notification,
        string $channel,
        string $status,
        array $context = []
    ): void {
        $summary = $context['summary'] ?? null;
        $data = $context['data'] ?? null;
        $recipient = $context['recipient'] ?? null;
        $error = $context['error_message'] ?? null;

        if (! $summary && $notification) {
            $summary = class_basename($notification);
        }

        if ($notification && ! is_array($data)) {
            try {
                $data = method_exists($notification, 'toArray')
                    ? $notification->toArray($notifiable)
                    : null;
            } catch (\Throwable) {
                $data = null;
            }
        }

        $request = request();
        $userAgent = $context['user_agent'] ?? ($request ? (string) $request->userAgent() : null);
        $ip = $context['ip_address'] ?? ($request ? $request->ip() : null);
        $requestId = $context['request_id'] ?? ($request ? $request->headers->get('X-Request-Id') : null);
        $notifiableType = is_object($notifiable) ? $notifiable::class : ($context['notifiable_type'] ?? null);
        $notifiableId = is_object($notifiable) && method_exists($notifiable, 'getKey')
            ? $notifiable->getKey()
            : ($context['notifiable_id'] ?? null);
        $notificationId = $context['notification_id'] ?? null;
        $attempts = $context['attempts'] ?? null;
        $idempotencyKey = $context['idempotency_key'] ?? null;
        $queuedAt = $context['queued_at'] ?? null;
        $sentAt = $context['sent_at'] ?? null;
        $failedAt = $context['failed_at'] ?? null;

        try {
            NotificationDelivery::query()->create([
                'notification_id' => $notificationId,
                'notification_type' => $notification ? $notification::class : ($context['notification_type'] ?? 'system'),
                'channel' => $channel,
                'status' => $status,
                'attempts' => is_numeric($attempts) ? (int) $attempts : 0,
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'recipient' => is_string($recipient) ? $recipient : null,
                'summary' => is_string($summary) ? Str::limit($summary, 250) : null,
                'data' => is_array($data) ? $data : null,
                'error_message' => is_string($error) ? Str::limit($error, 500) : null,
                'ip_address' => is_string($ip) ? $ip : null,
                'user_agent' => $userAgent ? Str::limit((string) $userAgent, 255) : null,
                'device_type' => self::guessDeviceType($userAgent),
                'request_id' => is_string($requestId) ? $requestId : null,
                'idempotency_key' => is_string($idempotencyKey) ? $idempotencyKey : null,
                'queued_at' => $queuedAt,
                'sent_at' => $sentAt,
                'failed_at' => $failedAt,
            ]);
        } catch (\Throwable) {
            // Ignore logging failures to avoid breaking notification flow.
        }
    }

    private static function guessDeviceType(?string $userAgent): ?string
    {
        if (! is_string($userAgent) || trim($userAgent) === '') {
            return null;
        }

        $ua = strtolower($userAgent);
        if (str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider')) {
            return 'bot';
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }
}

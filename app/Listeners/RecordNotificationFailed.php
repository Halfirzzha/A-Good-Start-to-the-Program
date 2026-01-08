<?php

namespace App\Listeners;

use App\Support\NotificationDeliveryLogger;
use Illuminate\Notifications\Events\NotificationFailed;

class RecordNotificationFailed
{
    public function handle(NotificationFailed $event): void
    {
        $recipient = null;
        if (method_exists($event->notifiable, 'routeNotificationFor')) {
            $recipient = $event->notifiable->routeNotificationFor($event->channel);
            if (is_array($recipient)) {
                $recipient = implode(', ', $recipient);
            }
        }

        NotificationDeliveryLogger::log(
            $event->notifiable,
            $event->notification,
            (string) $event->channel,
            'failed',
            [
                'recipient' => is_string($recipient) ? $recipient : null,
                'summary' => class_basename($event->notification),
                'data' => is_array($event->data) ? $event->data : null,
                'error_message' => 'Notification channel failed',
            ],
        );
    }
}

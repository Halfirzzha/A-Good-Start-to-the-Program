<?php

namespace App\Notifications;

use App\Models\NotificationMessage;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemBroadcastNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly NotificationMessage $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $priority = $this->message->priority ?? 'normal';

        $notification = FilamentNotification::make()
            ->title($this->message->title)
            ->body(strip_tags((string) $this->message->message))
            ->icon(self::iconForPriority($priority))
            ->iconColor(self::colorForPriority($priority))
            ->status(self::statusForPriority($priority))
            ->duration('persistent');

        $payload = $notification->getDatabaseMessage();

        $payload['notification_message_id'] = $this->message->getKey();
        $payload['category'] = $this->message->category;
        $payload['priority'] = $priority;
        $payload['expires_at'] = $this->message->expires_at?->toIso8601String();

        return $payload;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'notification_message_id' => $this->message->getKey(),
            'title' => $this->message->title,
            'message' => $this->message->message,
            'category' => $this->message->category,
            'priority' => $this->message->priority,
            'expires_at' => $this->message->expires_at?->toIso8601String(),
        ];
    }

    private static function statusForPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'warning',
            'critical' => 'danger',
            default => 'info',
        };
    }

    private static function colorForPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'warning',
            'critical' => 'danger',
            default => 'primary',
        };
    }

    private static function iconForPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'heroicon-o-exclamation-circle',
            'critical' => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-bell',
        };
    }
}

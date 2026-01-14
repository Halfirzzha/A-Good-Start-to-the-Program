<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Models\NotificationChannel;
use App\Models\NotificationMessage;
use App\Models\NotificationTarget;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\SystemBroadcastNotification;
use App\Support\LocaleHelper;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class NotificationCenterService
{
    public static function send(NotificationMessage $message): void
    {
        if ($message->status === 'sent') {
            return;
        }

        $channels = $message->channels()->where('enabled', true)->get();
        if ($channels->isEmpty()) {
            return;
        }

        $sentAny = false;

        foreach ($channels as $channel) {
            match ($channel->channel) {
                'inapp' => $sentAny = self::sendInApp($message, self::recipientsQuery($message)) || $sentAny,
                'email' => $sentAny = self::sendEmail($message, self::recipientsQuery($message)) || $sentAny,
                'telegram' => $sentAny = self::sendTelegram($message) || $sentAny,
                'sms' => $sentAny = self::sendSms($message) || $sentAny,
                default => null,
            };
        }

        if ($sentAny) {
            $message->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();
        }
    }

    private static function recipientsQuery(NotificationMessage $message): Builder
    {
        $query = User::query()
            ->where('account_status', AccountStatus::Active->value);

        if (! $message->target_all) {
            $roles = $message->targets()
                ->where('target_type', 'role')
                ->pluck('target_value')
                ->filter()
                ->values()
                ->all();

            if (! empty($roles)) {
                $query = self::applyRoleFilter($query, $roles);
            }
        }

        return $query;
    }

    /**
     * @param  list<string>  $roles
     */
    private static function applyRoleFilter(Builder $query, array $roles): Builder
    {
        return $query->where(function (Builder $builder) use ($roles): void {
            if (method_exists(User::class, 'roles')) {
                $builder->whereHas('roles', function (Builder $roleQuery) use ($roles): void {
                    $roleQuery->whereIn('name', $roles);
                });
            }

            $builder->orWhereIn('role', $roles);
        });
    }

    private static function sendInApp(NotificationMessage $message, Builder $query): bool
    {
        $notification = new SystemBroadcastNotification($message);
        $sentAny = false;
        $query->chunkById(200, function (Collection $chunk) use ($notification, $message, &$sentAny): void {
            if ($chunk->isEmpty()) {
                return;
            }

            $sentAny = true;
            Notification::send($chunk, $notification);
            if (config('broadcasting.default') !== 'null') {
                foreach ($chunk as $user) {
                    DatabaseNotificationsSent::dispatch($user);
                }
            }

            $now = now();
            $rows = $chunk->map(function (User $user) use ($message, $now): array {
                return [
                    'notification_id' => $message->getKey(),
                    'user_id' => $user->getKey(),
                    'channel' => 'inapp',
                    'is_read' => false,
                    'read_at' => null,
                    'delivered_at' => $now,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('user_notifications')->upsert(
                $rows,
                ['notification_id', 'user_id'],
                ['delivered_at', 'updated_at']
            );

            foreach ($chunk as $user) {
                $key = self::idempotencyKey($message, 'inapp', (string) $user->getKey());
                if (self::deliveryExists($key)) {
                    continue;
                }

                NotificationDeliveryLogger::log(
                    $user,
                    $notification,
                    'inapp',
                    'sent',
                    [
                        'notification_id' => $message->getKey(),
                        'recipient' => $user->email ?? (string) $user->getKey(),
                        'summary' => $message->title,
                        'attempts' => 1,
                        'idempotency_key' => $key,
                        'sent_at' => now(),
                    ],
                );
            }
        });

        return $sentAny;
    }

    private static function sendEmail(NotificationMessage $message, Builder $query): bool
    {
        $enabled = (bool) SystemSettings::getValue('notifications.email.enabled', false);
        if (! $enabled) {
            self::logSkipped($message, 'email', 'Email disabled');
            return false;
        }

        SystemSettings::applyMailConfig('general');
        $sentAny = false;
        $appName = (string) SystemSettings::getValue('project.name', config('app.name', 'System'));
        $logoUrl = SystemSettings::assetUrl('logo');
        $preheader = Str::limit(strip_tags((string) $message->message), 120, '...');
        $bodyHtml = self::sanitizeMessage((string) $message->message);
        $bodyText = trim(strip_tags($bodyHtml));
        $query = clone $query;
        $query->whereNotNull('email');

        $query->chunkById(200, function (Collection $chunk) use ($message, &$sentAny, $appName, $logoUrl, $preheader, $bodyHtml, $bodyText): void {
            foreach ($chunk as $user) {
                if (! $user->email) {
                    continue;
                }

                $key = self::idempotencyKey($message, 'email', $user->email);
                if (self::deliveryExists($key)) {
                    continue;
                }

                try {
                    $locale = LocaleHelper::resolveUserLocale($user);

                    LocaleHelper::withLocale($locale, function () use ($message, $user, $appName, $logoUrl, $preheader, $bodyHtml, $bodyText): void {
                        Mail::send(
                            [
                                'html' => 'emails.system-notification',
                                'text' => 'emails.text.system-notification',
                            ],
                            [
                                'title' => (string) $message->title,
                                'appName' => $appName,
                                'logoUrl' => $logoUrl,
                                'preheader' => $preheader,
                                'bodyHtml' => $bodyHtml,
                                'bodyText' => $bodyText,
                                'category' => (string) $message->category,
                                'priority' => strtoupper((string) $message->priority),
                                'actionUrl' => null,
                                'actionLabel' => null,
                                'footer' => __('notifications.email.footer'),
                            ],
                            function ($mail) use ($user, $message): void {
                                $mail->to($user->email)->subject($message->title);
                            }
                        );
                    });

                    $sentAny = true;
                    NotificationDeliveryLogger::log(
                        $user,
                        null,
                        'email',
                        'sent',
                        [
                            'notification_id' => $message->getKey(),
                            'recipient' => $user->email,
                            'summary' => $message->title,
                            'attempts' => 1,
                            'idempotency_key' => $key,
                            'sent_at' => now(),
                        ],
                    );
                } catch (\Throwable $error) {
                    NotificationDeliveryLogger::log(
                        $user,
                        null,
                        'email',
                        'failed',
                        [
                            'notification_id' => $message->getKey(),
                            'recipient' => $user->email,
                            'summary' => $message->title,
                            'attempts' => 1,
                            'idempotency_key' => $key,
                            'error_message' => $error->getMessage(),
                            'failed_at' => now(),
                        ],
                    );
                }
            }
        });

        return $sentAny;
    }

    private static function sendTelegram(NotificationMessage $message): bool
    {
        $enabled = (bool) SystemSettings::getValue('notifications.telegram.enabled', false);
        $chatId = (string) SystemSettings::getValue('notifications.telegram.chat_id', '');
        $token = (string) SystemSettings::getSecret('telegram.bot_token', '');

        if (! $enabled || $chatId === '' || $token === '') {
            self::logSkipped($message, 'telegram', 'Telegram config incomplete');
            return false;
        }

        $key = self::idempotencyKey($message, 'telegram', $chatId);
        if (self::deliveryExists($key)) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => strip_tags("{$message->title}\n{$message->message}"),
        ];

        try {
            $response = Http::timeout(6)
                ->retry(2, 200)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            if (! $response->successful()) {
                throw new \RuntimeException('Telegram error: '.$response->body());
            }

            NotificationDeliveryLogger::log(
                null,
                null,
                'telegram',
                'sent',
                [
                    'notification_id' => $message->getKey(),
                    'recipient' => $chatId,
                    'summary' => $message->title,
                    'attempts' => 1,
                    'idempotency_key' => $key,
                    'sent_at' => now(),
                ],
            );
            return true;
        } catch (\Throwable $error) {
            NotificationDeliveryLogger::log(
                null,
                null,
                'telegram',
                'failed',
                [
                    'notification_id' => $message->getKey(),
                    'recipient' => $chatId,
                    'summary' => $message->title,
                    'attempts' => 1,
                    'idempotency_key' => $key,
                    'error_message' => $error->getMessage(),
                    'failed_at' => now(),
                ],
            );
            return false;
        }
    }

    private static function sendSms(NotificationMessage $message): bool
    {
        self::logSkipped($message, 'sms', 'SMS provider not configured');
        return false;
    }

    private static function logSkipped(NotificationMessage $message, string $channel, string $reason): void
    {
        NotificationDeliveryLogger::log(
            null,
            null,
            $channel,
            'skipped',
            [
                'notification_id' => $message->getKey(),
                'summary' => $message->title,
                'error_message' => $reason,
                'attempts' => 0,
                'queued_at' => now(),
            ],
        );
    }

    private static function deliveryExists(string $key): bool
    {
        return \App\Models\NotificationDelivery::query()
            ->where('idempotency_key', $key)
            ->exists();
    }

    private static function idempotencyKey(NotificationMessage $message, string $channel, string $recipient): string
    {
        return Str::limit(hash('sha256', $message->getKey().'|'.$channel.'|'.$recipient), 100, '');
    }

    private static function sanitizeMessage(string $message): string
    {
        $allowed = '<p><br><strong><b><em><i><ul><ol><li><a>';
        $clean = strip_tags($message, $allowed);

        return $clean === '' ? '<p>'.__('notifications.ui.common.no_content').'</p>' : $clean;
    }

    /**
     * @return array<string, string>
     */
    public static function channelOptions(): array
    {
        return [
            'inapp' => __('notifications.ui.channels.inapp'),
            'email' => __('notifications.ui.channels.email'),
            'telegram' => __('notifications.ui.channels.telegram'),
            'sms' => __('notifications.ui.channels.sms'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            'maintenance' => __('notifications.ui.categories.maintenance'),
            'announcement' => __('notifications.ui.categories.announcement'),
            'update' => __('notifications.ui.categories.update'),
            'security' => __('notifications.ui.categories.security'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorityOptions(): array
    {
        return [
            'normal' => __('notifications.ui.priorities.normal'),
            'high' => __('notifications.ui.priorities.high'),
            'critical' => __('notifications.ui.priorities.critical'),
        ];
    }
}

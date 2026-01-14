<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\NotificationMessage;
use App\Models\NotificationTarget;
use App\Models\User;
use App\Support\NotificationCenterService;
use App\Support\SystemSettings;
use App\Support\LocaleHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Support\NotificationDeliveryLogger;

class SendSecurityAlert implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var array<string, mixed>
     */
    public array $payload;

    public int $tries = 4;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300, 600];

    public int $uniqueFor = 300;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function uniqueId(): string
    {
        $event = (string) ($this->payload['event'] ?? 'alert');
        $requestId = (string) ($this->payload['request_id'] ?? '');

        return sha1($event.'|'.$requestId.'|'.($this->payload['user_id'] ?? 'guest'));
    }

    public function handle(): void
    {
        if (! config('security.alert_enabled', true)) {
            return;
        }

        $message = $this->formatMessage($this->payload);
        $this->sendInAppAlert($message);

        $telegramEnabled = (bool) SystemSettings::getValue('notifications.telegram.enabled', false);
        $telegramToken = (string) SystemSettings::getSecret('telegram.bot_token', '');
        $telegramChatId = (string) SystemSettings::getValue('notifications.telegram.chat_id', '');

        $sentTelegram = false;
        $telegramError = null;
        if ($telegramEnabled && $telegramToken !== '' && $telegramChatId !== '') {
            $telegramResult = $this->sendTelegram($telegramToken, $telegramChatId, $message);
            $sentTelegram = (bool) ($telegramResult['ok'] ?? false);
            $telegramError = $telegramResult['error'] ?? null;
        }

        if ($sentTelegram) {
            NotificationDeliveryLogger::log(
                null,
                null,
                'telegram',
                'sent',
                [
                    'notification_type' => 'security_alert',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $this->payload['user_id'] ?? null,
                    'recipient' => $telegramChatId,
                    'summary' => $this->payload['title'] ?? 'Security Alert',
                    'data' => $this->payload,
                    'ip_address' => $this->payload['ip_observed'] ?? null,
                    'user_agent' => $this->payload['user_agent'] ?? null,
                    'request_id' => $this->payload['request_id'] ?? null,
                ],
            );
            return;
        }

        if ($telegramEnabled && $telegramToken !== '' && $telegramChatId !== '') {
            NotificationDeliveryLogger::log(
                null,
                null,
                'telegram',
                'failed',
                [
                    'notification_type' => 'security_alert',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $this->payload['user_id'] ?? null,
                    'recipient' => $telegramChatId,
                    'summary' => $this->payload['title'] ?? 'Security Alert',
                    'data' => $this->payload,
                    'error_message' => $telegramError ?: 'Telegram delivery failed',
                    'ip_address' => $this->payload['ip_observed'] ?? null,
                    'user_agent' => $this->payload['user_agent'] ?? null,
                    'request_id' => $this->payload['request_id'] ?? null,
                ],
            );
        }

        $emailEnabled = (bool) SystemSettings::getValue('notifications.email.enabled', false);
        $recipients = SystemSettings::getValue('notifications.email.recipients', []);
        $recipients = is_array($recipients) ? array_filter($recipients) : [];
        if (empty($recipients)) {
            $recipients = (array) config('security.threat_detection.alert.emails', []);
        }

        if (! $emailEnabled || empty($recipients)) {
            return;
        }

        try {
            LocaleHelper::withLocale(config('app.locale', 'en'), function () use ($recipients, $message): void {
                $appName = (string) SystemSettings::getValue('project.name', config('app.name', 'System'));
                $logoUrl = SystemSettings::assetUrl('logo');
                $title = (string) ($this->payload['title'] ?? __('notifications.email.security_alert.subject'));
                $event = (string) ($this->payload['event'] ?? 'security_alert');
                $requestId = (string) ($this->payload['request_id'] ?? '');
                $ipAddress = (string) ($this->payload['ip_observed'] ?? '');
                $messagePlain = trim(str_replace('*', '', $message));
                $bodyHtml = nl2br(e($messagePlain));

                $fromAddress = (string) SystemSettings::getValue('notifications.email.from_address', '');
                $fromName = (string) SystemSettings::getValue('notifications.email.from_name', '');
                SystemSettings::applyMailConfig('general');
                Mail::send(
                    [
                        'html' => 'emails.security-alert',
                        'text' => 'emails.text.security-alert',
                    ],
                    [
                        'title' => $title,
                        'appName' => $appName,
                        'logoUrl' => $logoUrl,
                        'preheader' => __('notifications.email.security_alert.preheader'),
                        'bodyHtml' => $bodyHtml,
                        'bodyText' => $messagePlain,
                        'event' => $event,
                        'requestId' => $requestId,
                        'ipAddress' => $ipAddress,
                        'actionUrl' => null,
                        'actionLabel' => null,
                        'footer' => __('notifications.email.footer'),
                    ],
                    function ($mail) use ($recipients, $fromAddress, $fromName, $title): void {
                        $mail->to($recipients)->subject($title);
                        if ($fromAddress !== '') {
                            $mail->from($fromAddress, $fromName !== '' ? $fromName : null);
                        }
                    }
                );
            });
            NotificationDeliveryLogger::log(
                null,
                null,
                'mail',
                'sent',
                [
                    'notification_type' => 'security_alert',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $this->payload['user_id'] ?? null,
                    'recipient' => implode(', ', $recipients),
                    'summary' => $title,
                    'data' => $this->payload,
                    'ip_address' => $this->payload['ip_observed'] ?? null,
                    'user_agent' => $this->payload['user_agent'] ?? null,
                    'request_id' => $this->payload['request_id'] ?? null,
                ],
            );
        } catch (\Throwable $error) {
            Log::channel('security')->warning('security.alert.email_failed', [
                'error' => $error->getMessage(),
                'recipients' => $recipients,
            ]);
            NotificationDeliveryLogger::log(
                null,
                null,
                'mail',
                'failed',
                [
                    'notification_type' => 'security_alert',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $this->payload['user_id'] ?? null,
                    'recipient' => implode(', ', $recipients),
                    'summary' => $this->payload['title'] ?? 'Security Alert',
                    'data' => $this->payload,
                    'error_message' => $error->getMessage(),
                    'ip_address' => $this->payload['ip_observed'] ?? null,
                    'user_agent' => $this->payload['user_agent'] ?? null,
                    'request_id' => $this->payload['request_id'] ?? null,
                ],
            );
        }
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function sendTelegram(string $token, string $chatId, string $message): array
    {
        try {
            $response = Http::timeout(6)
                ->retry(2, 200)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'error' => null];
            }

            Log::channel('security')->warning('security.alert.telegram_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return ['ok' => false, 'error' => 'HTTP '.$response->status()];
        } catch (\Throwable $error) {
            Log::channel('security')->warning('security.alert.telegram_exception', [
                'error' => $error->getMessage(),
            ]);
            return ['ok' => false, 'error' => $error->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatMessage(array $payload): string
    {
        $event = (string) ($payload['event'] ?? 'security_alert');
        $title = (string) ($payload['title'] ?? $event);
        $username = trim((string) ($payload['username'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $identity = trim((string) ($payload['identity'] ?? ''));

        if ($identity === '') {
            $identity = $username !== '' ? $username : ($email !== '' ? $email : 'guest');
        }

        $lines = [
            "*Security Alert*",
            "*Event:* {$title}",
            "*User:* {$identity}",
        ];

        if ($username !== '' && $username !== $identity) {
            $lines[] = "*Username:* {$username}";
        }

        if ($email !== '' && $email !== $identity) {
            $lines[] = "*Email:* {$email}";
        }

        $ipObserved = (string) ($payload['ip_observed'] ?? 'unknown');
        $ipPublic = $payload['ip_public'] ?? null;
        $ipPrivate = $payload['ip_private'] ?? null;
        $proxy = (string) ($payload['proxy_chain'] ?? '');

        if ($ipPublic) {
            $lines[] = "*Client IP (public):* {$ipPublic}";
        }

        if ($ipPrivate) {
            $lines[] = "*Client IP (private):* {$ipPrivate}";
        }

        if (! $ipPublic && ! $ipPrivate) {
            $lines[] = "*Client IP (observed):* {$ipObserved}";
        }

        if ($proxy !== '') {
            $lines[] = "*Proxy Chain:* {$proxy}";
        }

        $userAgent = (string) ($payload['user_agent'] ?? '');
        if ($userAgent !== '') {
            $lines[] = "*User-Agent:* ".Str::limit($userAgent, 180, '...');
        }

        $method = (string) ($payload['method'] ?? '');
        $path = (string) ($payload['path'] ?? '');
        if ($method !== '' || $path !== '') {
            $lines[] = "*Request:* {$method} {$path}";
        }

        $requestId = (string) ($payload['request_id'] ?? '');
        if ($requestId !== '') {
            $lines[] = "*Request ID:* {$requestId}";
        }

        $timestamp = (string) ($payload['timestamp'] ?? '');
        if ($timestamp !== '') {
            $lines[] = "*Time:* {$timestamp}";
        }

        $context = $payload['context'] ?? null;
        if (is_array($context) && ! empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $json = Str::limit($json, 1400, '...');
                $lines[] = "*Context:* `{$json}`";
            }
        }

        return implode("\n", $lines);
    }

    private function sendInAppAlert(string $message): void
    {
        if (! config('security.alert_in_app', true)) {
            return;
        }

        $roles = config('security.alert_roles', []);
        if (! is_array($roles) || empty($roles)) {
            return;
        }

        $requestId = (string) ($this->payload['request_id'] ?? '');
        $event = (string) ($this->payload['event'] ?? 'security_alert');
        $userId = $this->payload['user_id'] ?? 'guest';
        $hash = sha1($event.'|'.$requestId.'|'.$userId);

        $existing = NotificationMessage::query()
            ->where('metadata->security_hash', $hash)
            ->first();

        if ($existing) {
            if ($existing->status !== 'sent') {
                NotificationCenterService::send($existing);
            }
            return;
        }

        $title = (string) ($this->payload['title'] ?? 'Security Alert');
        $now = now();

        $notification = NotificationMessage::query()->create([
            'title' => $title,
            'message' => $message,
            'category' => 'security',
            'priority' => 'high',
            'status' => 'draft',
            'target_all' => false,
            'scheduled_at' => null,
            'sent_at' => null,
            'expires_at' => $now->copy()->addDays(30),
            'metadata' => [
                'security_hash' => $hash,
                'security_event' => $event,
                'request_id' => $requestId !== '' ? $requestId : null,
            ],
            'created_by' => is_int($userId) ? $userId : null,
            'updated_by' => is_int($userId) ? $userId : null,
        ]);

        $targetRows = collect($roles)
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => [
                'notification_id' => $notification->getKey(),
                'target_type' => 'role',
                'target_value' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if (! empty($targetRows)) {
            NotificationTarget::query()->insert($targetRows);
        }

        NotificationChannel::query()->create([
            'notification_id' => $notification->getKey(),
            'channel' => 'inapp',
            'enabled' => true,
        ]);

        NotificationCenterService::send($notification);
    }
}

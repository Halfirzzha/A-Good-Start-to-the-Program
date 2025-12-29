<?php

namespace App\Jobs;

use App\Support\SystemSettings;
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
        $message = $this->formatMessage($this->payload);

        $telegramEnabled = (bool) SystemSettings::getValue('notifications.telegram.enabled', false);
        $telegramToken = (string) SystemSettings::getSecret('telegram.bot_token', '');
        $telegramChatId = (string) SystemSettings::getValue('notifications.telegram.chat_id', '');

        $sentTelegram = false;
        if ($telegramEnabled && $telegramToken !== '' && $telegramChatId !== '') {
            $sentTelegram = $this->sendTelegram($telegramToken, $telegramChatId, $message);
        }

        if ($sentTelegram) {
            return;
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
            Mail::raw($message, function ($mail) use ($recipients): void {
                $mail->to($recipients)->subject('Security Alert');
            });
        } catch (\Throwable $error) {
            Log::channel('security')->warning('security.alert.email_failed', [
                'error' => $error->getMessage(),
                'recipients' => $recipients,
            ]);
        }
    }

    private function sendTelegram(string $token, string $chatId, string $message): bool
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
                return true;
            }

            Log::channel('security')->warning('security.alert.telegram_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $error) {
            Log::channel('security')->warning('security.alert.telegram_exception', [
                'error' => $error->getMessage(),
            ]);
        }

        return false;
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
}

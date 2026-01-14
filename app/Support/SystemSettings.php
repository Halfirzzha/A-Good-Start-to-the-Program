<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class SystemSettings
{
    private const CACHE_KEY = 'system_settings:current';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{data: array<string, mixed>, secrets: array<string, mixed>}
     */
    public static function get(bool $fresh = false): array
    {
        $cached = null;
        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            // Ignore cache failures and fall back to DB.
        }

        if (! $fresh && is_array($cached)) {
            return $cached;
        }

        $defaults = self::defaults();

        try {
            if (! Schema::hasTable('system_settings')) {
                return is_array($cached) ? $cached : $defaults;
            }

            $setting = SystemSetting::query()->first();
            if (! $setting) {
                return is_array($cached) ? $cached : $defaults;
            }

            $mapped = self::mapRecord($setting);
            $payload = [
                'data' => array_replace_recursive($defaults['data'], $mapped['data']),
                'secrets' => array_replace_recursive($defaults['secrets'], $mapped['secrets']),
            ];

            try {
                Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL_SECONDS);
            } catch (\Throwable) {
                // Ignore cache failures.
            }

            return $payload;
        } catch (\Throwable) {
            return is_array($cached) ? $cached : $defaults;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return self::get()['data'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function secrets(): array
    {
        return self::get()['secrets'];
    }

    public static function getValue(string $path, mixed $default = null): mixed
    {
        return Arr::get(self::data(), $path, $default);
    }

    public static function getSecret(string $path, mixed $default = null): mixed
    {
        return Arr::get(self::secrets(), $path, $default);
    }

    public static function assetUrl(string $key): ?string
    {
        $asset = Arr::get(self::data(), 'branding.'.$key, []);
        if (! is_array($asset)) {
            return null;
        }

        $url = self::diskUrl($asset['disk'] ?? null, $asset['path'] ?? null);
        if ($url) {
            return $url;
        }

        return self::diskUrl($asset['fallback_disk'] ?? null, $asset['fallback_path'] ?? null);
    }

    public static function applyMailConfig(string $context = 'general'): void
    {
        $settings = self::get();
        $data = $settings['data'] ?? [];
        $secrets = $settings['secrets'] ?? [];

        $email = Arr::get($data, 'notifications.email', []);
        if (! is_array($email)) {
            return;
        }

        $mailer = (string) ($email['mailer'] ?? 'smtp');
        $smtpPassword = Arr::get($secrets, 'notifications.email.smtp_password');

        config([
            'mail.default' => $mailer,
        ]);

        $smtpHost = $email['smtp_host'] ?? null;
        $smtpEncryption = $email['smtp_encryption'] ?? null;
        if ($smtpEncryption === '') {
            $smtpEncryption = null;
        }
        $smtpScheme = $smtpEncryption === 'ssl' ? 'smtps' : 'smtp';
        if (is_string($smtpHost) && $smtpHost !== '') {
            config([
                'mail.mailers.smtp.host' => $smtpHost,
                'mail.mailers.smtp.port' => (int) ($email['smtp_port'] ?? 587),
                'mail.mailers.smtp.encryption' => $smtpEncryption,
                'mail.mailers.smtp.username' => $email['smtp_username'] ?? null,
                'mail.mailers.smtp.password' => $smtpPassword ?? config('mail.mailers.smtp.password'),
                'mail.mailers.smtp.scheme' => $smtpScheme,
                'mail.mailers.smtp.timeout' => 10,
            ]);
        }

        $fromAddress = (string) ($email['from_address'] ?? '');
        $fromName = (string) ($email['from_name'] ?? '');
        if ($context === 'auth') {
            $fromAddress = (string) ($email['auth_from_address'] ?? $fromAddress);
            $fromName = (string) ($email['auth_from_name'] ?? $fromName);
        }

        if ($fromAddress !== '') {
            config([
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $fromName !== '' ? $fromName : config('app.name'),
            ]);
        }
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Ignore cache failures.
        }
    }

    /**
     * @return array{data: array<string, mixed>, secrets: array<string, mixed>}
     */
    public static function defaults(): array
    {
        return [
            'data' => [
                'project' => [
                    'name' => config('app.name', 'System'),
                    'description' => '',
                    'url' => config('app.url'),
                ],
                'branding' => [
                    'logo' => [
                        'disk' => null,
                        'path' => null,
                        'fallback_disk' => null,
                        'fallback_path' => null,
                        'status' => 'unset',
                        'updated_at' => null,
                    ],
                    'cover' => [
                        'disk' => null,
                        'path' => null,
                        'fallback_disk' => null,
                        'fallback_path' => null,
                        'status' => 'unset',
                        'updated_at' => null,
                    ],
                    'favicon' => [
                        'disk' => null,
                        'path' => null,
                        'fallback_disk' => null,
                        'fallback_path' => null,
                        'status' => 'unset',
                        'updated_at' => null,
                    ],
                ],
                'storage' => [
                    'primary_disk' => 'google',
                    'fallback_disk' => 'public',
                    'drive_root' => 'Warex-System',
                    'drive_folder_branding' => 'branding',
                    'drive_folder_favicon' => 'branding',
                ],
                'notifications' => [
                    'email' => [
                        'enabled' => true,
                        'recipients' => [],
                        'provider' => 'SMTP',
                        'from_address' => null,
                        'from_name' => null,
                        'auth_from_address' => null,
                        'auth_from_name' => null,
                        'mailer' => 'smtp',
                        'smtp_host' => null,
                        'smtp_port' => 587,
                        'smtp_encryption' => 'tls',
                        'smtp_username' => null,
                    ],
                    'telegram' => [
                        'enabled' => false,
                        'chat_id' => null,
                    ],
                ],
            ],
            'secrets' => [
                'telegram' => [
                    'bot_token' => null,
                ],
                'notifications' => [
                    'email' => [
                        'smtp_password' => null,
                    ],
                ],
                'google_drive' => [
                    'service_account_json' => null,
                    'client_id' => null,
                    'client_secret' => null,
                    'refresh_token' => null,
                ],
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, secrets: array<string, mixed>}
     */
    private static function mapRecord(SystemSetting $setting): array
    {
        $recipients = $setting->email_recipients;
        if (! is_array($recipients)) {
            $recipients = [];
        }

        return [
            'data' => [
                'project' => [
                    'name' => $setting->project_name ?: config('app.name', 'System'),
                    'description' => $setting->project_description,
                    'url' => $setting->project_url ?: config('app.url'),
                ],
                'branding' => [
                    'logo' => self::brandingMeta($setting, 'logo'),
                    'cover' => self::brandingMeta($setting, 'cover'),
                    'favicon' => self::brandingMeta($setting, 'favicon'),
                ],
                'storage' => [
                    'primary_disk' => $setting->storage_primary_disk,
                    'fallback_disk' => $setting->storage_fallback_disk,
                    'drive_root' => $setting->storage_drive_root,
                    'drive_folder_branding' => $setting->storage_drive_folder_branding,
                    'drive_folder_favicon' => $setting->storage_drive_folder_favicon,
                ],
                'notifications' => [
                    'email' => [
                        'enabled' => (bool) $setting->email_enabled,
                        'recipients' => $recipients,
                        'provider' => $setting->email_provider,
                        'from_address' => $setting->email_from_address,
                        'from_name' => $setting->email_from_name,
                        'auth_from_address' => $setting->email_auth_from_address,
                        'auth_from_name' => $setting->email_auth_from_name,
                        'mailer' => $setting->smtp_mailer,
                        'smtp_host' => $setting->smtp_host,
                        'smtp_port' => $setting->smtp_port,
                        'smtp_encryption' => $setting->smtp_encryption,
                        'smtp_username' => $setting->smtp_username,
                    ],
                    'telegram' => [
                        'enabled' => (bool) $setting->telegram_enabled,
                        'chat_id' => $setting->telegram_chat_id,
                    ],
                ],
            ],
            'secrets' => [
                'notifications' => [
                    'email' => [
                        'smtp_password' => $setting->smtp_password,
                    ],
                ],
                'telegram' => [
                    'bot_token' => $setting->telegram_bot_token,
                ],
                'google_drive' => [
                    'service_account_json' => $setting->google_drive_service_account_json,
                    'client_id' => $setting->google_drive_client_id,
                    'client_secret' => $setting->google_drive_client_secret,
                    'refresh_token' => $setting->google_drive_refresh_token,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function brandingMeta(SystemSetting $setting, string $key): array
    {
        $updatedAt = $setting->getAttribute("branding_{$key}_updated_at");
        if ($updatedAt instanceof \DateTimeInterface) {
            $updatedAt = $updatedAt->format(\DateTimeInterface::ATOM);
        }

        return [
            'disk' => $setting->getAttribute("branding_{$key}_disk"),
            'path' => $setting->getAttribute("branding_{$key}_path"),
            'fallback_disk' => $setting->getAttribute("branding_{$key}_fallback_disk"),
            'fallback_path' => $setting->getAttribute("branding_{$key}_fallback_path"),
            'status' => $setting->getAttribute("branding_{$key}_status"),
            'updated_at' => $updatedAt,
        ];
    }

    private static function diskUrl(?string $disk, ?string $path): ?string
    {
        if (! $disk || ! $path) {
            return null;
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use App\Support\SystemSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SystemSetting extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_name',
        'project_description',
        'project_url',
        'branding_logo_disk',
        'branding_logo_path',
        'branding_logo_fallback_disk',
        'branding_logo_fallback_path',
        'branding_logo_status',
        'branding_logo_updated_at',
        'branding_cover_disk',
        'branding_cover_path',
        'branding_cover_fallback_disk',
        'branding_cover_fallback_path',
        'branding_cover_status',
        'branding_cover_updated_at',
        'branding_favicon_disk',
        'branding_favicon_path',
        'branding_favicon_fallback_disk',
        'branding_favicon_fallback_path',
        'branding_favicon_status',
        'branding_favicon_updated_at',
        'storage_primary_disk',
        'storage_fallback_disk',
        'storage_drive_root',
        'storage_drive_folder_branding',
        'storage_drive_folder_favicon',
        'email_enabled',
        'email_provider',
        'email_from_name',
        'email_from_address',
        'email_auth_from_name',
        'email_auth_from_address',
        'email_recipients',
        'smtp_mailer',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'telegram_enabled',
        'telegram_chat_id',
        'telegram_bot_token',
        'google_drive_service_account_json',
        'google_drive_client_id',
        'google_drive_client_secret',
        'google_drive_refresh_token',
        // AI Configuration
        'ai_enabled',
        'ai_provider',
        'ai_api_key',
        'ai_organization',
        'ai_model',
        'ai_max_tokens',
        'ai_temperature',
        'ai_timeout',
        'ai_retry_attempts',
        'ai_rate_limit_rpm',
        'ai_rate_limit_tpm',
        'ai_rate_limit_tpd',
        'ai_daily_usage',
        'ai_usage_reset_date',
        'ai_feature_security_analysis',
        'ai_feature_anomaly_detection',
        'ai_feature_threat_classification',
        'ai_feature_log_summarization',
        'ai_feature_smart_alerts',
        'ai_feature_auto_response',
        'ai_feature_chat_assistant',
        'ai_alert_high_risk_score',
        'ai_alert_suspicious_patterns',
        'ai_alert_failed_logins',
        'ai_alert_anomaly_confidence',
        'ai_action_auto_block_ip',
        'ai_action_auto_lock_user',
        'ai_action_notify_admin',
        'ai_action_create_incident',
        'ai_action_custom_rules',
        'updated_by',
        'updated_ip',
        'updated_user_agent',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'branding_logo_updated_at' => 'datetime',
        'branding_cover_updated_at' => 'datetime',
        'branding_favicon_updated_at' => 'datetime',
        'email_enabled' => 'boolean',
        'email_recipients' => 'array',
        'smtp_port' => 'integer',
        'telegram_enabled' => 'boolean',
        'smtp_password' => 'encrypted',
        'telegram_bot_token' => 'encrypted',
        'google_drive_service_account_json' => 'encrypted',
        'google_drive_client_id' => 'encrypted',
        'google_drive_client_secret' => 'encrypted',
        'google_drive_refresh_token' => 'encrypted',
        // AI Casts
        'ai_enabled' => 'boolean',
        'ai_api_key' => 'encrypted',
        'ai_max_tokens' => 'integer',
        'ai_temperature' => 'decimal:2',
        'ai_timeout' => 'integer',
        'ai_retry_attempts' => 'integer',
        'ai_rate_limit_rpm' => 'integer',
        'ai_rate_limit_tpm' => 'integer',
        'ai_rate_limit_tpd' => 'integer',
        'ai_daily_usage' => 'integer',
        'ai_usage_reset_date' => 'date',
        'ai_feature_security_analysis' => 'boolean',
        'ai_feature_anomaly_detection' => 'boolean',
        'ai_feature_threat_classification' => 'boolean',
        'ai_feature_log_summarization' => 'boolean',
        'ai_feature_smart_alerts' => 'boolean',
        'ai_feature_auto_response' => 'boolean',
        'ai_feature_chat_assistant' => 'boolean',
        'ai_alert_high_risk_score' => 'integer',
        'ai_alert_suspicious_patterns' => 'integer',
        'ai_alert_failed_logins' => 'integer',
        'ai_alert_anomaly_confidence' => 'decimal:2',
        'ai_action_auto_block_ip' => 'boolean',
        'ai_action_auto_lock_user' => 'boolean',
        'ai_action_notify_admin' => 'boolean',
        'ai_action_create_incident' => 'boolean',
        'ai_action_custom_rules' => 'array',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = [
        'smtp_password',
        'telegram_bot_token',
        'google_drive_service_account_json',
        'google_drive_client_id',
        'google_drive_client_secret',
        'google_drive_refresh_token',
        'ai_api_key',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            if (app()->runningInConsole()) {
                return;
            }

            $request = request();
            $userId = Auth::id();

            if ($userId) {
                $setting->updated_by = $userId;
            }

            if ($request) {
                $setting->updated_ip = $request->ip();
                $setting->updated_user_agent = self::truncate((string) $request->userAgent(), 255);
            }

            if (! self::canUpdateSecrets()) {
                $blocked = false;
                foreach (self::secretColumns() as $column) {
                    if ($setting->isDirty($column)) {
                        $setting->setAttribute($column, $setting->getOriginal($column));
                        $setting->syncOriginalAttribute($column);
                        $blocked = true;
                    }
                }

                if ($blocked) {
                    self::logSecretUpdateDenied($request, $userId);
                }
            }

            if (! self::canUpdateProjectUrl() && $setting->isDirty('project_url')) {
                $setting->setAttribute('project_url', $setting->getOriginal('project_url'));
                $setting->syncOriginalAttribute('project_url');
                self::logProjectUrlUpdateDenied($request, $userId);
            }
        });

        static::created(function (self $setting): void {
            $setting->recordVersion('created');
            SystemSettings::forget();
        });

        static::updated(function (self $setting): void {
            $setting->recordVersion('updated');
            SystemSettings::forget();
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SystemSettingVersion::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private function recordVersion(string $action): void
    {
        try {
            if (! Schema::hasTable('system_setting_versions')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $originalAttributes = $this->getRawOriginal();
        $currentAttributes = $this->getAttributes();

        $originalData = $this->buildDataPayload($originalAttributes);
        $originalSecrets = $this->buildSecretsPayload($originalAttributes);
        $currentData = $this->buildDataPayload($currentAttributes);
        $currentSecrets = $this->buildSecretsPayload($currentAttributes);

        $dataChanges = self::diffKeys($originalData, $currentData);
        $secretChanges = self::diffKeys($originalSecrets, $currentSecrets);

        $changedKeys = array_merge(
            $dataChanges,
            array_map(fn (string $key): string => 'secrets.'.$key, $secretChanges)
        );

        $request = request();
        $actorId = Auth::id();
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;
        SystemSettingVersion::create([
            'system_setting_id' => $this->getKey(),
            'action' => $action,
            'snapshot' => [
                'data' => $currentData,
                'secrets' => self::maskSecrets($currentSecrets),
            ],
            'changed_keys' => $changedKeys,
            'actor_id' => $actorId,
            'request_id' => $request?->headers->get('X-Request-Id'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? self::truncate((string) $request->userAgent(), 255) : null,
            'context' => [
                'updated_by' => $actorId,
            ],
            'created_at' => now(),
        ]);

        if ($action === 'updated' && ! empty($changedKeys)) {
            SecurityAlert::dispatch('system_settings_updated', [
                'title' => 'System settings updated',
                'changed_keys' => $changedKeys,
            ], $request);
        }

        if (! empty($secretChanges)) {
            AuditLogWriter::writeAudit([
                'user_id' => $actorId,
                'action' => 'system_settings_secrets_updated',
                'auditable_type' => self::class,
                'auditable_id' => $this->getKey(),
                'old_values' => null,
                'new_values' => null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? self::truncate((string) $request->userAgent(), 255) : null,
                'url' => $request?->fullUrl(),
                'route' => $request?->route()?->getName(),
                'method' => $request?->method(),
                'status_code' => null,
                'request_id' => $request?->headers->get('X-Request-Id'),
                'session_id' => $sessionId,
                'duration_ms' => null,
                'context' => [
                    'changed_keys' => $secretChanges,
                ],
                'created_at' => now(),
            ]);

            SecurityAlert::dispatch('system_settings_secrets_updated', [
                'title' => 'System settings secrets updated',
                'changed_keys' => $secretChanges,
            ], $request);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function buildDataPayload(array $attributes): array
    {
        $recipients = self::normalizeArray($attributes['email_recipients'] ?? []);

        return [
            'project' => [
                'name' => $attributes['project_name'] ?? config('app.name', 'System'),
                'description' => $attributes['project_description'] ?? null,
                'url' => $attributes['project_url'] ?? config('app.url'),
            ],
            'branding' => [
                'logo' => $this->brandingMetaFromAttributes($attributes, 'logo'),
                'cover' => $this->brandingMetaFromAttributes($attributes, 'cover'),
                'favicon' => $this->brandingMetaFromAttributes($attributes, 'favicon'),
            ],
            'storage' => [
                'primary_disk' => $attributes['storage_primary_disk'] ?? 'google',
                'fallback_disk' => $attributes['storage_fallback_disk'] ?? 'public',
                'drive_root' => $attributes['storage_drive_root'] ?? null,
                'drive_folder_branding' => $attributes['storage_drive_folder_branding'] ?? null,
                'drive_folder_favicon' => $attributes['storage_drive_folder_favicon'] ?? null,
            ],
            'notifications' => [
                'email' => [
                    'enabled' => (bool) ($attributes['email_enabled'] ?? false),
                    'recipients' => $recipients,
                    'provider' => $attributes['email_provider'] ?? null,
                    'from_address' => $attributes['email_from_address'] ?? null,
                    'from_name' => $attributes['email_from_name'] ?? null,
                    'auth_from_address' => $attributes['email_auth_from_address'] ?? null,
                    'auth_from_name' => $attributes['email_auth_from_name'] ?? null,
                    'mailer' => $attributes['smtp_mailer'] ?? 'smtp',
                    'smtp_host' => $attributes['smtp_host'] ?? null,
                    'smtp_port' => $attributes['smtp_port'] ?? 587,
                    'smtp_encryption' => $attributes['smtp_encryption'] ?? null,
                    'smtp_username' => $attributes['smtp_username'] ?? null,
                ],
                'telegram' => [
                    'enabled' => (bool) ($attributes['telegram_enabled'] ?? false),
                    'chat_id' => $attributes['telegram_chat_id'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function buildSecretsPayload(array $attributes): array
    {
        return [
            'notifications' => [
                'email' => [
                    'smtp_password' => $attributes['smtp_password'] ?? null,
                ],
            ],
            'telegram' => [
                'bot_token' => $attributes['telegram_bot_token'] ?? null,
            ],
            'google_drive' => [
                'service_account_json' => $attributes['google_drive_service_account_json'] ?? null,
                'client_id' => $attributes['google_drive_client_id'] ?? null,
                'client_secret' => $attributes['google_drive_client_secret'] ?? null,
                'refresh_token' => $attributes['google_drive_refresh_token'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function brandingMetaFromAttributes(array $attributes, string $key): array
    {
        return [
            'disk' => $attributes["branding_{$key}_disk"] ?? null,
            'path' => $attributes["branding_{$key}_path"] ?? null,
            'fallback_disk' => $attributes["branding_{$key}_fallback_disk"] ?? null,
            'fallback_path' => $attributes["branding_{$key}_fallback_path"] ?? null,
            'status' => $attributes["branding_{$key}_status"] ?? null,
            'updated_at' => $attributes["branding_{$key}_updated_at"] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function secretColumns(): array
    {
        return [
            'smtp_password',
            'telegram_bot_token',
            'google_drive_service_account_json',
            'google_drive_client_id',
            'google_drive_client_secret',
            'google_drive_refresh_token',
            'ai_api_key',
        ];
    }

    private static function canUpdateSecrets(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_setting_secrets')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings');
    }

    private static function canUpdateProjectUrl(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_setting_project_url')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings');
    }

    private static function logSecretUpdateDenied(?\Illuminate\Http\Request $request, ?int $userId): void
    {
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => $userId,
            'action' => 'system_settings_secrets_denied',
            'auditable_type' => self::class,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? self::truncate((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'status_code' => 403,
            'request_id' => $request?->headers->get('X-Request-Id'),
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'reason' => 'secrets_update_denied',
            ],
            'created_at' => now(),
        ]);
    }

    private static function logProjectUrlUpdateDenied(?\Illuminate\Http\Request $request, ?int $userId): void
    {
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => $userId,
            'action' => 'system_settings_project_url_denied',
            'auditable_type' => self::class,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? self::truncate((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'status_code' => 403,
            'request_id' => $request?->headers->get('X-Request-Id'),
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'reason' => 'project_url_update_denied',
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private static function diffKeys(array $before, array $after, string $prefix = ''): array
    {
        $keys = [];
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($allKeys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if (is_array($beforeValue) && is_array($afterValue)) {
                $keys = array_merge($keys, self::diffKeys($beforeValue, $afterValue, $path));
                continue;
            }

            if ($beforeValue !== $afterValue) {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function maskSecrets(array $secrets): array
    {
        $masked = [];

        foreach ($secrets as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = self::maskSecrets($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $masked[$key] = '[redacted]';
                continue;
            }

            $masked[$key] = filled($value) ? '[redacted]' : null;
        }

        return $masked;
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}

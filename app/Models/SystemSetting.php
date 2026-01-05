<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\AuditLogWriter;
use App\Support\MaintenanceService;
use App\Support\SecurityAlert;
use App\Support\SystemSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SystemSetting extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'data',
        'secrets',
        'updated_by',
        'updated_ip',
        'updated_user_agent',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'secrets' => 'encrypted:array',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = [
        'secrets',
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

            if ($setting->isDirty('secrets') && ! self::canUpdateSecrets()) {
                $setting->secrets = self::normalizeArray($setting->getOriginal('secrets'));
                $setting->syncOriginalAttribute('secrets');

                self::logSecretUpdateDenied($request, $userId);
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

        $originalData = self::normalizeArray($this->getOriginal('data'));
        $originalSecrets = self::normalizeArray($this->getOriginal('secrets'));
        $currentData = self::normalizeArray($this->data);
        $currentSecrets = self::normalizeArray($this->secrets);

        $dataChanges = self::diffKeys($originalData, $currentData);
        $secretChanges = self::diffKeys($originalSecrets, $currentSecrets);

        $changedKeys = array_merge(
            $dataChanges,
            array_map(fn (string $key): string => 'secrets.'.$key, $secretChanges)
        );

        $request = request();
        $actorId = Auth::id();
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;
        $maintenanceChanges = array_values(array_filter(
            $dataChanges,
            static fn (string $key): bool => Str::startsWith($key, 'maintenance.')
        ));

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

        $oldMaintenance = Arr::get($originalData, 'maintenance.enabled');
        $newMaintenance = Arr::get($currentData, 'maintenance.enabled');
        if ($oldMaintenance !== $newMaintenance) {
            SecurityAlert::dispatch('maintenance_toggle', [
                'title' => 'Maintenance mode toggled',
                'enabled' => $newMaintenance,
                'start_at' => Arr::get($currentData, 'maintenance.start_at'),
                'end_at' => Arr::get($currentData, 'maintenance.end_at'),
            ], $request);
        }

        if ($action === 'updated' && ! empty($maintenanceChanges)) {
            $beforeMaintenance = Arr::get($originalData, 'maintenance', []);
            $afterMaintenance = Arr::get($currentData, 'maintenance', []);
            $diff = MaintenanceService::diffMaintenance($beforeMaintenance, $afterMaintenance);

            AuditLogWriter::writeAudit([
                'user_id' => $actorId,
                'action' => 'maintenance_settings_updated',
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
                    'changed_keys' => $maintenanceChanges,
                    'changes' => $diff,
                    'maintenance' => [
                        'enabled' => Arr::get($currentData, 'maintenance.enabled'),
                        'mode' => Arr::get($currentData, 'maintenance.mode'),
                        'start_at' => Arr::get($currentData, 'maintenance.start_at'),
                        'end_at' => Arr::get($currentData, 'maintenance.end_at'),
                    ],
                ],
                'created_at' => now(),
            ]);

            $beforeEnabled = (bool) Arr::get($beforeMaintenance, 'enabled', false);
            $afterEnabled = (bool) Arr::get($afterMaintenance, 'enabled', false);
            if ($beforeEnabled !== $afterEnabled) {
                AuditLogWriter::writeAudit([
                    'user_id' => $actorId,
                    'action' => $afterEnabled ? 'maintenance_enabled' : 'maintenance_disabled',
                    'auditable_type' => self::class,
                    'auditable_id' => $this->getKey(),
                    'old_values' => ['enabled' => $beforeEnabled],
                    'new_values' => ['enabled' => $afterEnabled],
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
                        'reason' => 'manual_update',
                    ],
                    'created_at' => now(),
                ]);
            }

            $scheduleChanged = Arr::get($beforeMaintenance, 'start_at') !== Arr::get($afterMaintenance, 'start_at')
                || Arr::get($beforeMaintenance, 'end_at') !== Arr::get($afterMaintenance, 'end_at');

            if ($scheduleChanged) {
                AuditLogWriter::writeAudit([
                    'user_id' => $actorId,
                    'action' => 'maintenance_schedule_updated',
                    'auditable_type' => self::class,
                    'auditable_id' => $this->getKey(),
                    'old_values' => [
                        'start_at' => Arr::get($beforeMaintenance, 'start_at'),
                        'end_at' => Arr::get($beforeMaintenance, 'end_at'),
                    ],
                    'new_values' => [
                        'start_at' => Arr::get($afterMaintenance, 'start_at'),
                        'end_at' => Arr::get($afterMaintenance, 'end_at'),
                    ],
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
                        'changes' => $diff,
                    ],
                    'created_at' => now(),
                ]);
            }

            $noteChanged = Arr::get($beforeMaintenance, 'note_html') !== Arr::get($afterMaintenance, 'note_html');
            if ($noteChanged) {
                AuditLogWriter::writeAudit([
                    'user_id' => $actorId,
                    'action' => 'maintenance_note_updated',
                    'auditable_type' => self::class,
                    'auditable_id' => $this->getKey(),
                    'old_values' => ['note_html' => Arr::get($beforeMaintenance, 'note_html')],
                    'new_values' => ['note_html' => Arr::get($afterMaintenance, 'note_html')],
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
                        'changes' => $diff,
                    ],
                    'created_at' => now(),
                ]);
            }
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

    private static function canUpdateSecrets(): bool
    {
        $user = Auth::user();

        return $user && method_exists($user, 'isDeveloper') && $user->isDeveloper();
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

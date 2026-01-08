<?php

namespace App\Support;

use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use App\Support\MaintenanceService;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemHealth
{
    public const ALERT_CACHE_KEY = 'system_health:alerted';

    /**
     * @return array<string, mixed>
     */
    public static function run(): array
    {
        $start = microtime(true);

        $checks = [
            'database' => self::checkDatabase(),
            'cache' => self::checkCache(),
            'queue' => self::checkQueue(),
            'scheduler' => self::checkScheduler(),
            'storage' => self::checkStorage(),
            'system' => self::checkSystemResources(),
        ];

        $status = collect($checks)->every(function (array $check): bool {
            return in_array($check['status'], ['ok', 'restricted', 'unknown'], true);
        }) ? 'ok' : 'degraded';

        return [
            'overall_status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'maintenance' => self::maintenanceSnapshot(),
            'app' => self::appSnapshot(),
        ];
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public static function maybeAlert(array $results, Request $request): void
    {
        $status = $results['overall_status'] ?? 'degraded';

        if ($status === 'ok') {
            Cache::forget(self::ALERT_CACHE_KEY);
            return;
        }

        if (Cache::has(self::ALERT_CACHE_KEY)) {
            return;
        }

        SecurityAlert::dispatch('system_health_degraded', [
            'title' => 'System health degraded',
            'details' => $results,
        ], $request);

        AuditLogWriter::writeAudit([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => 'system_health_alert',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'url' => $request->fullUrl(),
            'route' => (string) optional($request->route())->getName(),
            'method' => $request->getMethod(),
            'status_code' => null,
            'request_id' => $request->headers->get('X-Request-Id'),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'duration_ms' => null,
            'context' => [
                'health_snapshot' => $results,
            ],
            'created_at' => now(),
        ]);

        Cache::put(self::ALERT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(5));
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            return self::formatCheck('database', 'ok', 'Database connection available', $start);
        } catch (\Throwable $error) {
            return self::formatCheck('database', 'degraded', 'Unable to reach database', $start, [
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkCache(): array
    {
        $start = microtime(true);
        try {
            Cache::store()->put('system_health:probe', now()->timestamp, 5);

            return self::formatCheck('cache', 'ok', 'Cache writable', $start);
        } catch (\Throwable $error) {
            return self::formatCheck('cache', 'degraded', 'Cache not writable', $start, [
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkQueue(): array
    {
        $start = microtime(true);
        try {
            $size = Queue::size();
            return self::formatCheck('queue', 'ok', 'Queue worker accessible', $start, [
                'pending_jobs' => $size,
            ]);
        } catch (\Throwable $error) {
            return self::formatCheck('queue', 'degraded', 'Unable to read queue size', $start, [
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkScheduler(): array
    {
        $start = microtime(true);
        $lastRun = Cache::get('system_health:scheduler_last_run');
        if (! is_string($lastRun) || $lastRun === '') {
            return self::formatCheck('scheduler', 'degraded', 'Scheduler heartbeat missing', $start);
        }

        $lastRunAt = self::formatDate($lastRun);
        $status = $lastRunAt && now()->diffInSeconds($lastRunAt, false) >= -180 ? 'ok' : 'degraded';
        $details = $status === 'ok' ? 'Scheduler heartbeat ok' : 'Scheduler heartbeat stale';

        return self::formatCheck('scheduler', $status, $details, $start, [
            'last_run' => $lastRunAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkStorage(): array
    {
        $start = microtime(true);
        $disk = (string) config('filesystems.default', 'local');
        $probePath = 'healthcheck/.probe-' . Str::random(8);

        try {
            $storage = Storage::disk($disk);
            $storage->put($probePath, now()->toIso8601String());
            $storage->delete($probePath);

            $meta = [
                'disk' => $disk,
            ];

            $root = config("filesystems.disks.{$disk}.root");
            if (is_string($root) && is_dir($root)) {
                $free = @disk_free_space($root);
                if (is_float($free) || is_int($free)) {
                    $meta['free_mb'] = (int) round($free / 1024 / 1024);
                }
            }

            return self::formatCheck('storage', 'ok', 'Storage writable', $start, $meta);
        } catch (\Throwable $error) {
            return self::formatCheck('storage', 'degraded', 'Storage not writable', $start, [
                'disk' => $disk,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkSystemResources(): array
    {
        $start = microtime(true);

        try {
            $cores = self::cpuCores();
            $loadAvg = sys_getloadavg();
            $load1m = is_array($loadAvg) && isset($loadAvg[0]) ? (float) $loadAvg[0] : null;
            $cpuUsage = ($load1m !== null && $cores > 0)
                ? min(100, max(0, ($load1m / $cores) * 100))
                : null;

            $memory = self::readMemory();
            $disk = self::readDisk();

            if ($load1m === null && empty($memory) && empty($disk)) {
                return self::formatCheck('system', 'restricted', 'Privasi Provider - data sensitif', $start);
            }

            return self::formatCheck('system', 'ok', 'System resources available', $start, [
                'cpu_load_1m' => $load1m,
                'cpu_cores' => $cores,
                'cpu_usage_pct' => $cpuUsage,
                'memory_total_mb' => $memory['total_mb'] ?? null,
                'memory_used_mb' => $memory['used_mb'] ?? null,
                'memory_free_mb' => $memory['free_mb'] ?? null,
                'disk_total_gb' => $disk['total_gb'] ?? null,
                'disk_used_gb' => $disk['used_gb'] ?? null,
                'disk_free_gb' => $disk['free_gb'] ?? null,
            ]);
        } catch (\Throwable) {
            return self::formatCheck('system', 'restricted', 'Privasi Provider - data sensitif', $start);
        }
    }

    private static function cpuCores(): int
    {
        $cores = 0;
        if (is_readable('/proc/cpuinfo')) {
            $content = file_get_contents('/proc/cpuinfo');
            if (is_string($content)) {
                $cores = preg_match_all('/^processor\\s*:/m', $content);
            }
        }

        return $cores > 0 ? $cores : 1;
    }

    /**
     * @return array{total_mb?: int, used_mb?: int, free_mb?: int}
     */
    private static function readMemory(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return [];
        }

        $content = file_get_contents('/proc/meminfo');
        if (! is_string($content)) {
            return [];
        }

        $values = [];
        foreach (explode("\n", $content) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $values[$key] = (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        }

        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;
        if (! $total || ! $available) {
            return [];
        }

        $used = max(0, $total - $available);

        return [
            'total_mb' => (int) round($total / 1024),
            'used_mb' => (int) round($used / 1024),
            'free_mb' => (int) round($available / 1024),
        ];
    }

    /**
     * @return array{total_gb?: float, used_gb?: float, free_gb?: float}
     */
    private static function readDisk(): array
    {
        $root = base_path();
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        if (! is_int($total) && ! is_float($total)) {
            return [];
        }
        if (! is_int($free) && ! is_float($free)) {
            return [];
        }

        $used = max(0, $total - $free);

        return [
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_gb' => round($used / 1024 / 1024 / 1024, 2),
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
        ];
    }

    /**
     * @param  string  $name
     * @param  string  $status
     * @param  string  $details
     * @param  float  $start
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function formatCheck(string $name, string $status, string $details, float $start, array $meta = []): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'details' => $details,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function maintenanceSnapshot(): array
    {
        $maintenance = SystemSettings::getValue('maintenance', []);
        $noteHtml = $maintenance['note_html'] ?? ($maintenance['note'] ?? null);

        return [
            'enabled' => (bool) ($maintenance['enabled'] ?? false),
            'mode' => (string) ($maintenance['mode'] ?? 'global'),
            'start_at' => self::formatDate($maintenance['start_at'] ?? null),
            'end_at' => self::formatDate($maintenance['end_at'] ?? null),
            'title' => $maintenance['title'] ?? null,
            'summary' => $maintenance['summary'] ?? null,
            'note_html' => $noteHtml,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function appSnapshot(): array
    {
        $bootedAt = Cache::get('system_health:booted_at');
        if (! is_string($bootedAt) || $bootedAt === '') {
            $bootedAt = now()->toIso8601String();
            Cache::put('system_health:booted_at', $bootedAt, now()->addHours(12));
        }

        $bootedAtFormatted = self::formatDate($bootedAt);
        $uptimeSeconds = $bootedAtFormatted ? now()->diffInSeconds($bootedAtFormatted) : null;

        return [
            'name' => config('app.name'),
            'version' => config('app.version', 'unknown'),
            'timezone' => config('app.timezone', 'UTC'),
            'booted_at' => $bootedAtFormatted,
            'uptime_seconds' => $uptimeSeconds,
        ];
    }

    /**
     * @param  mixed  $value
     */
    private static function formatDate(mixed $value): ?string
    {
        $parsed = MaintenanceService::parseDate($value);
        return $parsed?->toIso8601String();
    }
}

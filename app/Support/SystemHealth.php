<?php

namespace App\Support;

use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use App\Support\MaintenanceService;
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
            'security' => self::checkSecurityBaseline(),
        ];

        $statuses = collect($checks)->pluck('status')->all();
        $status = 'ok';
        if (in_array('degraded', $statuses, true)) {
            $status = 'degraded';
        } elseif (in_array('warn', $statuses, true) || in_array('restricted', $statuses, true)) {
            $status = 'warn';
        }

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
            Cache::forget('system_health:degraded_since');
            return;
        }

        if (Cache::has(self::ALERT_CACHE_KEY)) {
            return;
        }

        $degradedSince = Cache::get('system_health:degraded_since');
        if (! is_string($degradedSince) || $degradedSince === '') {
            Cache::put('system_health:degraded_since', now()->toIso8601String(), now()->addMinutes(30));
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

        $since = Cache::get('system_health:degraded_since');
        $sinceAt = self::formatDate($since);
        if ($sinceAt && now()->diffInMinutes($sinceAt) >= 10) {
            SecurityAlert::dispatch('system_health_sustained', [
                'title' => 'System health degraded > 10 minutes',
                'details' => [
                    'overall_status' => $results['overall_status'] ?? null,
                    'checks' => array_keys((array) ($results['checks'] ?? [])),
                    'since' => $sinceAt,
                ],
            ], $request);
        }
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
        $driver = (string) config('cache.default', 'unknown');
        try {
            Cache::store()->put('system_health:probe', now()->timestamp, 5);

            return self::formatCheck('cache', 'ok', 'Cache writable', $start, [
                'driver' => $driver,
            ]);
        } catch (\Throwable $error) {
            return self::formatCheck('cache', 'degraded', 'Cache not writable', $start, [
                'driver' => $driver,
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
            $meta = [
                'pending_jobs' => $size,
            ];

            $failedJobs = self::failedJobsCount();
            if ($failedJobs !== null) {
                $meta['failed_jobs'] = $failedJobs;
            } else {
                $meta['failed_jobs'] = null;
                $meta['failed_jobs_note'] = 'Provider restricted or table missing';
            }

            $status = ($failedJobs !== null && $failedJobs > 0) ? 'warn' : 'ok';
            $details = $status === 'warn' ? 'Failed jobs detected' : 'Queue worker accessible';

            return self::formatCheck('queue', $status, $details, $start, $meta);
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

    /**
     * @return array<string, mixed>
     */
    private static function checkSecurityBaseline(): array
    {
        $start = microtime(true);

        $debug = (bool) config('app.debug', false);
        $enforceEmail = (bool) config('security.enforce_email_verification', true);
        $enforceSession = (bool) config('security.enforce_session_stamp', true);
        $enforceAccount = (bool) config('security.enforce_account_status', true);
        $threatEnabled = (bool) config('security.threat_detection.enabled', true);
        $developerBypass = (bool) config('security.developer_bypass_validations', false);

        $reasons = [];
        if ($debug) {
            $reasons[] = 'Debug mode enabled';
        }
        if (! $enforceEmail) {
            $reasons[] = 'Email verification disabled';
        }
        if (! $enforceSession) {
            $reasons[] = 'Session stamp not enforced';
        }
        if (! $enforceAccount) {
            $reasons[] = 'Account status not enforced';
        }
        if (! $threatEnabled) {
            $reasons[] = 'Threat detection disabled';
        }
        if ($developerBypass) {
            $reasons[] = 'Developer bypass enabled';
        }

        $status = empty($reasons) ? 'ok' : 'warn';
        $details = empty($reasons) ? 'Security baseline ok' : 'Security baseline needs review';

        return self::formatCheck('security', $status, $details, $start, [
            'debug' => $debug,
            'enforce_email_verification' => $enforceEmail,
            'enforce_session_stamp' => $enforceSession,
            'enforce_account_status' => $enforceAccount,
            'threat_detection' => $threatEnabled,
            'developer_bypass' => $developerBypass,
            'reasons' => $reasons,
        ]);
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

    private static function failedJobsCount(): ?int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                return null;
            }
            return \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return null;
        }
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
        $maintenance = MaintenanceService::getSettings();
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
        $env = (string) config('app.env', 'production');
        $deployment = $env === 'production' ? 'production' : 'non-production';

        return [
            'name' => config('app.name'),
            'version' => config('app.version', 'unknown'),
            'timezone' => config('app.timezone', 'UTC'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'mail_driver' => config('mail.default'),
            'deployment' => $deployment,
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

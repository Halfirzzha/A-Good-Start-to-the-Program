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
        ];

        $status = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok') ? 'ok' : 'degraded';

        return [
            'overall_status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'maintenance' => self::maintenanceSnapshot(),
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
     * @param  mixed  $value
     */
    private static function formatDate(mixed $value): ?string
    {
        $parsed = MaintenanceService::parseDate($value);
        return $parsed?->toIso8601String();
    }
}

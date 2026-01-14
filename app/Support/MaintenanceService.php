<?php

namespace App\Support;

use App\Models\MaintenanceSetting;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MaintenanceService
{
    private const CACHE_KEY = 'maintenance_settings:current';

    private const CACHE_TTL_SECONDS = 10;

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(bool $fresh = false): array
    {
        $cached = null;
        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            $cached = null;
        }

        if (! $fresh && is_array($cached)) {
            return $cached;
        }

        $defaults = self::defaults();

        try {
            if (! Schema::hasTable('maintenance_settings')) {
                return is_array($cached) ? $cached : $defaults;
            }

            $record = MaintenanceSetting::query()->first();
            if (! $record) {
                return is_array($cached) ? $cached : $defaults;
            }

            $payload = array_replace_recursive($defaults, self::mapRecord($record));

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

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Ignore cache failures.
        }
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @return array<string, mixed>
     */
    public static function snapshot(array $maintenance, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $startAt = self::parseDate($maintenance['start_at'] ?? null);
        $endAt = self::parseDate($maintenance['end_at'] ?? null);

        $scheduledActive = $startAt ? $now->greaterThanOrEqualTo($startAt) : false;
        if ($scheduledActive && $endAt) {
            $scheduledActive = $now->lessThanOrEqualTo($endAt);
        }

        $enabled = (bool) ($maintenance['enabled'] ?? false);
        $isActive = $enabled || $scheduledActive;

        $statusLabel = 'Disabled';
        if ($isActive) {
            $statusLabel = 'Active';
        } elseif ($startAt && $now->lessThan($startAt)) {
            $statusLabel = 'Scheduled';
        } elseif ($endAt && $now->greaterThan($endAt)) {
            $statusLabel = 'Ended';
        }

        $retryAfter = null;
        if ($endAt) {
            $seconds = $now->diffInSeconds($endAt, false);
            if ($seconds > 0) {
                $retryAfter = $seconds;
            }
        }

        return [
            'status_label' => $statusLabel,
            'is_active' => $isActive,
            'is_scheduled' => $startAt && $now->lessThan($startAt),
            'scheduled_active' => $scheduledActive,
            'enabled' => $enabled,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'enabled' => false,
            'mode' => 'global',
            'title' => null,
            'summary' => null,
            'note_html' => null,
            'start_at' => null,
            'end_at' => null,
            'retry' => null,
            'retry_after' => null,
            'allow_roles' => [],
            'allow_ips' => [],
            'allow_paths' => [],
            'deny_paths' => [],
            'allow_routes' => [],
            'deny_routes' => [],
            'allow_api' => false,
            'allow_developer_bypass' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapRecord(MaintenanceSetting $record): array
    {
        return [
            'enabled' => (bool) $record->enabled,
            'mode' => $record->mode ?: 'global',
            'title' => $record->title,
            'summary' => $record->summary,
            'note_html' => $record->note_html,
            'start_at' => $record->start_at,
            'end_at' => $record->end_at,
            'retry' => $record->retry_after,
            'retry_after' => $record->retry_after,
            'allow_roles' => $record->allow_roles ?: [],
            'allow_ips' => $record->allow_ips ?: [],
            'allow_paths' => $record->allow_paths ?: [],
            'deny_paths' => $record->deny_paths ?: [],
            'allow_routes' => $record->allow_routes ?: [],
            'deny_routes' => $record->deny_routes ?: [],
            'allow_api' => (bool) $record->allow_api,
            'allow_developer_bypass' => (bool) $record->allow_developer_bypass,
        ];
    }

    public static function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        if (is_float($value)) {
            return Carbon::createFromTimestampMs((int) round($value * 1000));
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            return Carbon::createFromTimestampMs((int) $trimmed);
        }

        try {
            return Carbon::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function sanitizeNote(?string $html): ?string
    {
        if (! is_string($html) || $html === '') {
            return null;
        }

        $allowed = '<p><br><strong><b><em><i><ul><ol><li><a><blockquote><code><pre>';
        $clean = strip_tags($html, $allowed);

        $clean = preg_replace('/\son[a-z]+\s*=\s*([\"\']).*?\1/i', '', $clean);
        $clean = preg_replace('/\sstyle\s*=\s*([\"\']).*?\1/i', '', $clean);
        $clean = preg_replace('/\shref\s*=\s*([\"\'])\s*javascript:[^\"\']*\1/i', '', $clean);
        $clean = preg_replace('/\shref\s*=\s*([\"\'])\s*data:[^\"\']*\1/i', '', $clean);

        $clean = trim($clean);

        return $clean !== '' ? $clean : null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, array{field:string, from:mixed, to:mixed}>
     */
    public static function diffMaintenance(array $before, array $after): array
    {
        $fields = [
            'enabled' => 'Maintenance Mode',
            'start_at' => 'Maintenance Start',
            'end_at' => 'Maintenance End',
            'retry_after' => 'Retry After',
            'mode' => 'Mode',
            'title' => 'Title',
            'summary' => 'Summary',
            'note_html' => 'Operator Note',
            'allow_ips' => 'Allow IPs',
            'allow_roles' => 'Allow Roles',
            'allow_paths' => 'Allow Paths',
            'deny_paths' => 'Deny Paths',
            'allow_routes' => 'Allow Routes',
            'deny_routes' => 'Deny Routes',
            'allow_api' => 'Allow API',
            'allow_developer_bypass' => 'Allow Developer Bypass',
        ];

        $changes = [];
        foreach ($fields as $key => $label) {
            $from = Arr::get($before, $key);
            $to = Arr::get($after, $key);

            if ($from === $to) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'from' => self::stringify($from),
                'to' => self::stringify($to),
            ];
        }

        return $changes;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '[' . get_debug_type($value) . ']' : $json;
    }
}

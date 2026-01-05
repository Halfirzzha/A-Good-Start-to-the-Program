<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Arr;

class MaintenanceService
{
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

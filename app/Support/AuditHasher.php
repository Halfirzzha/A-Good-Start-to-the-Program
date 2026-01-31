<?php

namespace App\Support;

use DateTimeInterface;

class AuditHasher
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public const AUDIT_HASH_KEYS = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'route',
        'method',
        'status_code',
        'request_id',
        'session_id',
        'duration_ms',
        'context',
        'created_at',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $normalized = [];

        foreach (self::AUDIT_HASH_KEYS as $key) {
            $value = $data[$key] ?? null;

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            if (in_array($key, ['old_values', 'new_values', 'context'], true)) {
                $value = self::normalizeJsonString($value);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function normalizeJsonString(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $value;
        }

        $normalized = self::normalizeJsonValue($decoded);

        return json_encode($normalized, self::JSON_FLAGS);
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private static function normalizeJsonValue(array $value): array
    {
        if (self::isAssociativeArray($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalizeJsonValue($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private static function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function hash(array $data, ?string $previousHash): string
    {
        $payload = json_encode($data, self::JSON_FLAGS);

        return hash('sha256', ($previousHash ?? '').'|'.$payload);
    }

    public static function signature(string $hash): ?string
    {
        $enabled = (bool) config('audit.signature_enabled', false);
        if (! $enabled) {
            return null;
        }

        $secret = (string) config('audit.signature_secret', '');
        if ($secret === '') {
            if (app()->environment('production')) {
                throw new \RuntimeException('Audit signature enabled but AUDIT_SIGNATURE_SECRET is empty.');
            }

            return null;
        }

        $algo = (string) config('audit.signature_algo', 'sha256');

        $signature = hash_hmac($algo, $hash, $secret);

        return is_string($signature) ? $signature : null;
    }
}

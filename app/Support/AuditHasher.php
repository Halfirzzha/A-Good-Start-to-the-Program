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

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function hash(array $data, ?string $previousHash): string
    {
        $payload = json_encode($data, self::JSON_FLAGS);

        return hash('sha256', ($previousHash ?? '').'|'.$payload);
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditLogWriter
{
    private static ?bool $auditHashColumnsReady = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function writeAudit(array $data): void
    {
        self::insertAudit($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function writeLoginActivity(array $data): void
    {
        $event = is_string($data['event'] ?? null) ? (string) $data['event'] : 'activity';
        $action = 'auth_' . $event;

        $context = $data['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        $context = array_merge($context, [
            'category' => 'auth',
            'event' => $event,
            'identity' => $data['identity'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
        ]);

        self::writeAudit([
            'user_id' => $data['user_id'] ?? null,
            'action' => $action,
            'auditable_type' => $data['user_id'] ? \App\Models\User::class : null,
            'auditable_id' => $data['user_id'] ?? null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'url' => $context['url'] ?? null,
            'route' => $context['route'] ?? null,
            'method' => $context['method'] ?? null,
            'status_code' => $context['status_code'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'context' => $context,
            'created_at' => $data['created_at'] ?? now(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $jsonColumns
     */
    private static function insert(string $table, array $data, array $jsonColumns): void
    {
        $data = self::encodeJsonColumns($data, $jsonColumns);
        try {
            DB::table($table)->insert($data);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insertAudit(array $data): void
    {
        $data = self::encodeJsonColumns($data, ['context', 'old_values', 'new_values']);
        $hashData = AuditHasher::normalize($data);

        if (! self::auditHashColumnsReady()) {
            self::insert('audit_logs', $data, []);
            return;
        }

        try {
            DB::transaction(function () use ($data, $hashData): void {
                $previousHash = DB::table('audit_logs')
                    ->select('hash')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->value('hash');

                $payloadHash = AuditHasher::hash($hashData, $previousHash);

                DB::table('audit_logs')->insert([
                    ...$data,
                    'previous_hash' => $previousHash,
                    'hash' => $payloadHash,
                ]);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $jsonColumns
     * @return array<string, mixed>
     */
    private static function encodeJsonColumns(array $data, array $jsonColumns): array
    {
        foreach ($jsonColumns as $column) {
            if (array_key_exists($column, $data) && is_array($data[$column])) {
                $data[$column] = json_encode($data[$column], AuditHasher::JSON_FLAGS);
            }
        }

        return $data;
    }

    private static function auditHashColumnsReady(): bool
    {
        if (self::$auditHashColumnsReady !== null) {
            return self::$auditHashColumnsReady;
        }

        try {
            self::$auditHashColumnsReady = Schema::hasColumns('audit_logs', ['hash', 'previous_hash']);
        } catch (Throwable) {
            self::$auditHashColumnsReady = false;
        }

        return self::$auditHashColumnsReady;
    }
}

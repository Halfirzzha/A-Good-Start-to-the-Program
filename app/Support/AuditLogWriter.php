<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AuditLogWriter
{
    private static ?bool $auditHashColumnsReady = null;
    private static ?bool $auditSignatureColumnReady = null;

    /**
     * Reset internal schema cache. Useful for testing.
     */
    public static function resetSchemaCache(): void
    {
        self::$auditHashColumnsReady = null;
        self::$auditSignatureColumnReady = null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function writeAudit(array $data): void
    {
        $data = self::applyActorSnapshot($data);
        $data = self::applyRequestSnapshot($data);
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
            'role_name' => $data['role_name'] ?? null,
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
                $signature = self::auditSignatureColumnReady() ? AuditHasher::signature($payloadHash) : null;

                DB::table('audit_logs')->insert([
                    ...$data,
                    'previous_hash' => $previousHash,
                    'hash' => $payloadHash,
                    'signature' => $signature,
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

    private static function auditSignatureColumnReady(): bool
    {
        if (self::$auditSignatureColumnReady !== null) {
            return self::$auditSignatureColumnReady;
        }

        try {
            self::$auditSignatureColumnReady = Schema::hasColumn('audit_logs', 'signature');
        } catch (Throwable) {
            self::$auditSignatureColumnReady = false;
        }

        return self::$auditSignatureColumnReady;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyActorSnapshot(array $data): array
    {
        try {
            /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
            $user = auth()->user();
        } catch (Throwable) {
            $user = null;
        }

        if (! $user) {
            return $data;
        }

        if (empty($data['user_name']) && property_exists($user, 'name')) {
            $data['user_name'] = $user->name;
        }

        if (empty($data['user_email']) && property_exists($user, 'email')) {
            $data['user_email'] = $user->email;
        }

        if (empty($data['user_username']) && property_exists($user, 'username')) {
            $data['user_username'] = $user->username;
        }

        $role = null;
        if (property_exists($user, 'role')) {
            $role = $user->role;
        }

        if (! $role && method_exists($user, 'getRoleNames')) {
            $role = $user->getRoleNames()->first();
        }

        if ($role) {
            $data['role_name'] = $role;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyRequestSnapshot(array $data): array
    {
        $request = request();
        if (! $request) {
            return $data;
        }

        if (empty($data['ip_address'])) {
            $data['ip_address'] = $request->ip();
        }

        if (empty($data['user_agent'])) {
            $data['user_agent'] = $request->userAgent();
        }

        if (empty($data['user_agent_hash']) && ! empty($data['user_agent'])) {
            $data['user_agent_hash'] = hash('sha256', (string) $data['user_agent']);
        }

        if (empty($data['url'])) {
            $data['url'] = $request->fullUrl();
        }

        if (empty($data['route'])) {
            $data['route'] = optional($request->route())->getName();
        }

        if (empty($data['method'])) {
            $data['method'] = $request->method();
        }

        if (empty($data['request_id'])) {
            $data['request_id'] = $request->headers->get('X-Request-Id');
        }

        if (empty($data['session_id']) && $request->hasSession()) {
            $data['session_id'] = $request->session()->getId();
        }

        if (empty($data['request_referer'])) {
            $data['request_referer'] = $request->headers->get('referer');
        }

        if (empty($data['request_payload_hash'])) {
            $payload = [
                'method' => $data['method'] ?? $request->method(),
                'route' => $data['route'] ?? optional($request->route())->getName(),
                'url' => $data['url'] ?? $request->fullUrl(),
                'query' => $request->query(),
                'input' => self::sanitizePayload($request->input()),
            ];
            $data['request_payload_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function sanitizePayload(array $payload): array
    {
        $sensitive = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'secret',
            'api_key',
            'key',
            'otp',
            'pin',
            'code',
        ];

        $filtered = [];

        foreach ($payload as $key => $value) {
            $keyString = strtolower((string) $key);
            $redact = false;

            foreach ($sensitive as $needle) {
                if (str_contains($keyString, $needle)) {
                    $redact = true;
                    break;
                }
            }

            if ($redact) {
                $filtered[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = self::sanitizePayload($value);
                continue;
            }

            if (is_string($value) && strlen($value) > 1000) {
                $filtered[$key] = Str::limit($value, 1000, '');
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}

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
    private static ?bool $spatieTablesReady = null;
    /** @var array<int|string, array<string, mixed>> */
    private static array $actorSnapshotCache = [];
    /** @var array<int|string, array<string, mixed>> */
    private static array $actorPermissionCache = [];
    /** @var array<int|string, array<string, mixed>> */
    private static array $actorRoleCache = [];
    private const PERMISSIONS_LIMIT = 150;

    /**
     * Reset internal schema cache. Useful for testing.
     */
    public static function resetSchemaCache(): void
    {
        self::$auditHashColumnsReady = null;
        self::$auditSignatureColumnReady = null;
        self::$spatieTablesReady = null;
        self::$actorSnapshotCache = [];
        self::$actorPermissionCache = [];
        self::$actorRoleCache = [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function writeAudit(array $data): void
    {
        $data = self::applyActorSnapshot($data);
        $data = self::applyRequestSnapshot($data);
        $data = self::applySensitiveRedaction($data);
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
        if (! self::auditHashColumnsReady()) {
            self::insert('audit_logs', $data, ['context', 'old_values', 'new_values']);
            return;
        }

        $signatureStrict = (bool) config('audit.signature_strict', false);
        $signatureEnabledConfig = (bool) config('audit.signature_enabled', false);
        if ($signatureStrict && $signatureEnabledConfig) {
            $secret = (string) config('audit.signature_secret', '');
            if ($secret === '') {
                throw new \RuntimeException('Audit signature failed; strict mode is enabled.');
            }
        }

        $insertAudit = function () use ($data, $signatureStrict): void {
            $previousHash = DB::table('audit_logs')
                ->select('hash')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('hash');

            $dataForInsert = self::encodeJsonColumns($data, ['context', 'old_values', 'new_values']);
            $hashData = AuditHasher::normalize($dataForInsert);
            $payloadHash = AuditHasher::hash($hashData, $previousHash);

            $signatureEnabled = self::auditSignatureColumnReady()
                && (bool) config('audit.signature_enabled', false);
            $signature = null;
            $signatureFailed = false;
            $signatureError = null;

            if ($signatureEnabled) {
                try {
                    $signature = AuditHasher::signature($payloadHash);
                } catch (Throwable $e) {
                    $signatureFailed = true;
                    $signatureError = $e->getMessage();
                }

                if (! is_string($signature) || $signature === '') {
                    $signatureFailed = true;
                    $signature = null;
                }
            }

            if ($signatureFailed && $signatureStrict) {
                throw new \RuntimeException('Audit signature failed; strict mode is enabled.');
            }

            if ($signatureFailed) {
                $data = self::attachSignatureFailure($data, $signatureError);
                $dataForInsert = self::encodeJsonColumns($data, ['context', 'old_values', 'new_values']);
                $hashData = AuditHasher::normalize($dataForInsert);
                $payloadHash = AuditHasher::hash($hashData, $previousHash);
            }

            DB::table('audit_logs')->insert([
                ...$dataForInsert,
                'previous_hash' => $previousHash,
                'hash' => $payloadHash,
                'signature' => $signature,
            ]);
        };

        try {
            if (DB::transactionLevel() > 0) {
                $insertAudit();
            } else {
                DB::transaction($insertAudit);
            }
        } catch (Throwable $e) {
            report($e);

            if ((bool) config('audit.signature_strict', false) && str_contains($e->getMessage(), 'Audit signature failed')) {
                throw $e;
            }
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
                $normalized = self::normalizeJsonValue($data[$column]);
                $data[$column] = json_encode($normalized, AuditHasher::JSON_FLAGS);
            }
        }

        return $data;
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
        $context = self::normalizeContext($data['context'] ?? null);
        $actor = self::resolveActor($data);

        if (! $actor) {
            $context['actor_id'] ??= $data['user_id'] ?? null;
            $context['actor_type'] ??= null;
            $context['role_at_time'] ??= null;
            $context['roles_at_time'] ??= [];
            $context['permissions_at_time'] ??= [];
            $context['subject_type'] ??= $data['auditable_type'] ?? null;
            $context['subject_id'] ??= $data['auditable_id'] ?? null;
            $data['context'] = $context;

            return $data;
        }

        $actorId = $actor->getAuthIdentifier();
        if (empty($data['user_id'])) {
            $data['user_id'] = $actorId;
        }

        if (empty($data['user_name']) && property_exists($actor, 'name')) {
            $data['user_name'] = $actor->name;
        }

        if (empty($data['user_email']) && property_exists($actor, 'email')) {
            $data['user_email'] = $actor->email;
        }

        if (empty($data['user_username']) && property_exists($actor, 'username')) {
            $data['user_username'] = $actor->username;
        }

        $roleSnapshot = self::resolveActorRoles($actor);
        $role = $roleSnapshot['primary'] ?? null;
        $roles = $roleSnapshot['all'] ?? [];

        if ($role && empty($data['role_name'])) {
            $data['role_name'] = $role;
        }

        $permissionsSnapshot = self::resolveActorPermissions($actor);

        $context['actor_id'] ??= $actorId;
        $context['actor_type'] ??= $actor::class;
        $context['role_at_time'] ??= $role;
        $context['roles_at_time'] ??= $roles;
        $context['permissions_at_time'] ??= $permissionsSnapshot['all'] ?? [];
        if (($permissionsSnapshot['truncated'] ?? false) && ! array_key_exists('permissions_truncated', $context)) {
            $context['permissions_truncated'] = true;
        }

        $context['subject_type'] ??= $data['auditable_type'] ?? null;
        $context['subject_id'] ??= $data['auditable_id'] ?? null;
        $data['context'] = $context;

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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applySensitiveRedaction(array $data): array
    {
        if (array_key_exists('old_values', $data)) {
            $data['old_values'] = self::sanitizeValues($data['old_values']);
        }

        if (array_key_exists('new_values', $data)) {
            $data['new_values'] = self::sanitizeValues($data['new_values']);
        }

        return $data;
    }

    /**
     * @param mixed $values
     * @return mixed
     */
    private static function sanitizeValues(mixed $values, int $depth = 0): mixed
    {
        if ($values === null || $depth > 5) {
            return $values;
        }

        if (! is_array($values)) {
            return $values;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            $keyString = is_string($key) ? $key : null;
            $sanitized[$key] = self::sanitizeValueForKey($keyString, $value, $depth + 1);
        }

        return $sanitized;
    }

    private static function sanitizeValueForKey(?string $key, mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return self::sanitizeValues($value, $depth);
        }

        $keyString = strtolower((string) ($key ?? ''));

        if ($keyString !== '' && self::isSensitiveKey($keyString)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            if ($keyString !== '' && str_contains($keyString, 'email')) {
                if (str_contains($value, '@') || filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return self::maskEmail($value);
                }

                return $value;
            }

            if ($keyString !== '' && (str_contains($keyString, 'phone') || str_contains($keyString, 'mobile') || str_contains($keyString, 'tel'))) {
                return self::maskPhone($value);
            }

            if ($keyString !== '' && str_contains($keyString, 'address')) {
                return self::maskAddress($value);
            }

            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return self::maskEmail($value);
            }
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        static $sensitive = null;

        if ($sensitive === null) {
            $config = array_map('strtolower', (array) config('audit.sensitive_keys', []));
            $sensitive = array_values(array_unique(array_merge($config, [
                'password',
                'current_password',
                'new_password',
                'password_confirmation',
                'token',
                'secret',
                'api_key',
                'remember_token',
            ])));
        }

        foreach ($sensitive as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function maskEmail(string $value): string
    {
        $email = SecurityService::sanitizeEmail($value);
        if ($email === '' || ! str_contains($email, '@')) {
            return '[REDACTED]';
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = $local ?: '';
        $first = $local !== '' ? substr($local, 0, 1) : '';
        $maskedLocal = $first . str_repeat('*', max(1, strlen($local) - 1));

        return $maskedLocal . '@' . $domain;
    }

    private static function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '[REDACTED]';
        }

        $keep = min(4, max(2, (int) floor(strlen($digits) / 3)));
        $masked = str_repeat('*', max(0, strlen($digits) - $keep)) . substr($digits, -$keep);

        return $masked;
    }

    private static function maskAddress(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '[REDACTED]';
        }

        $length = strlen($trimmed);
        if ($length <= 8) {
            return '[REDACTED]';
        }

        $prefix = substr($trimmed, 0, 3);
        $suffix = substr($trimmed, -3);
        $middle = str_repeat('*', max(3, $length - 6));

        return $prefix . $middle . $suffix;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function attachSignatureFailure(array $data, ?string $error = null): array
    {
        $context = [];

        if (array_key_exists('context', $data)) {
            if (is_array($data['context'])) {
                $context = $data['context'];
            } elseif (is_string($data['context'])) {
                $decoded = json_decode($data['context'], true);
                if (is_array($decoded)) {
                    $context = $decoded;
                }
            }
        }

        $context['signature_failed'] = true;
        if ($error) {
            $context['signature_error'] = Str::limit($error, 190, '');
        }

        $data['context'] = $context;

        if ((bool) config('audit.signature_alert', true) && app()->environment('production')) {
            if (class_exists(\App\Support\SecurityAlert::class)) {
                try {
                    \App\Support\SecurityAlert::dispatch('audit_signature_failed', [
                        'title' => 'Audit signature failed',
                        'action' => $data['action'] ?? null,
                        'request_id' => $data['request_id'] ?? null,
                        'error' => $error,
                    ]);
                } catch (Throwable) {
                    // swallow alert failures to avoid recursive errors
                }
            }
        }

        return $data;
    }

    /**
     * @param  mixed  $context
     * @return array<string, mixed>
     */
    private static function normalizeContext(mixed $context): array
    {
        return is_array($context) ? $context : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveActor(array $data): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $userId = $data['user_id'] ?? null;

        try {
            /** @var \Illuminate\Contracts\Auth\Authenticatable|null $authUser */
            $authUser = auth()->user();
        } catch (Throwable) {
            $authUser = null;
        }

        if ($authUser) {
            $authId = $authUser->getAuthIdentifier();
            if (! $userId || (string) $authId === (string) $userId) {
                return $authUser;
            }
        }

        if (! $userId) {
            return null;
        }

        if (isset(self::$actorSnapshotCache[$userId]['model'])) {
            $cached = self::$actorSnapshotCache[$userId]['model'];
            if ($cached instanceof \Illuminate\Contracts\Auth\Authenticatable) {
                return $cached;
            }
        }

        if (! class_exists(\App\Models\User::class)) {
            return null;
        }

        try {
            /** @var \Illuminate\Contracts\Auth\Authenticatable|null $actor */
            $actor = \App\Models\User::query()->find($userId);
        } catch (Throwable) {
            $actor = null;
        }

        if ($actor) {
            self::$actorSnapshotCache[$userId]['model'] = $actor;
        }

        return $actor;
    }

    /**
     * @return array{primary: string|null, all: array<int, string>}
     */
    private static function resolveActorRoles(\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        $userId = $user->getAuthIdentifier();
        if ($userId && isset(self::$actorRoleCache[$userId])) {
            return self::$actorRoleCache[$userId];
        }

        $role = null;
        $roles = [];

        try {
            if (property_exists($user, 'role')) {
                $role = $user->role ?: null;
            }
        } catch (Throwable) {
            $role = null;
        }

        if (method_exists($user, 'getRoleNames') && self::spatieTablesReady()) {
            try {
                $roles = $user->getRoleNames()->values()->all();
            } catch (Throwable) {
                $roles = [];
            }
        }

        if ($role && empty($roles)) {
            $roles = [$role];
        }

        if (! $role && ! empty($roles)) {
            $role = $roles[0];
        }

        $snapshot = [
            'primary' => $role ? (string) $role : null,
            'all' => array_values(array_filter(array_map('strval', $roles))),
        ];

        if ($userId) {
            self::$actorRoleCache[$userId] = $snapshot;
        }

        return $snapshot;
    }

    /**
     * @return array{all: array<int, string>, truncated: bool}
     */
    private static function resolveActorPermissions(\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        $userId = $user->getAuthIdentifier();
        if ($userId && isset(self::$actorPermissionCache[$userId])) {
            return self::$actorPermissionCache[$userId];
        }

        $permissions = [];
        $truncated = false;

        if (method_exists($user, 'getAllPermissions') && self::spatieTablesReady()) {
            try {
                $permissions = $user->getAllPermissions()
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->all();
            } catch (Throwable) {
                $permissions = [];
            }
        }

        if (count($permissions) > self::PERMISSIONS_LIMIT) {
            $permissions = array_slice($permissions, 0, self::PERMISSIONS_LIMIT);
            $truncated = true;
        }

        $snapshot = [
            'all' => array_values(array_map('strval', $permissions)),
            'truncated' => $truncated,
        ];

        if ($userId) {
            self::$actorPermissionCache[$userId] = $snapshot;
        }

        return $snapshot;
    }

    private static function spatieTablesReady(): bool
    {
        if (self::$spatieTablesReady !== null) {
            return self::$spatieTablesReady;
        }

        try {
            self::$spatieTablesReady = Schema::hasTable('roles')
                && Schema::hasTable('permissions')
                && Schema::hasTable('model_has_roles');
        } catch (Throwable) {
            self::$spatieTablesReady = false;
        }

        return self::$spatieTablesReady;
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

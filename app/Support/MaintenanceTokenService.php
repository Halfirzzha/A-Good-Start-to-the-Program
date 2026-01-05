<?php

namespace App\Support;

use App\Models\MaintenanceToken;
use App\Support\AuditLogWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MaintenanceTokenService
{
    /**
     * @return array{token: string, model: MaintenanceToken}
     */
    public static function create(array $data, ?int $actorId = null): array
    {
        $plain = self::generateToken();

        $model = MaintenanceToken::query()->create([
            'name' => $data['name'] ?? null,
            'token_hash' => Hash::make($plain),
            'created_by' => $actorId,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        self::logTokenEvent('maintenance_token_created', $model, $actorId, [
            'name' => $model->name,
            'expires_at' => $model->expires_at?->toIso8601String(),
        ]);

        return [
            'token' => $plain,
            'model' => $model,
        ];
    }

    /**
     * @return string New plaintext token
     */
    public static function rotate(MaintenanceToken $token, ?int $actorId = null): string
    {
        $plain = self::generateToken();

        $token->forceFill([
            'token_hash' => Hash::make($plain),
            'revoked_at' => null,
            'last_used_at' => null,
        ])->save();

        self::logTokenEvent('maintenance_token_rotated', $token, $actorId, [
            'token_id' => $token->getKey(),
        ]);

        return $plain;
    }

    public static function revoke(MaintenanceToken $token, ?int $actorId = null): void
    {
        if ($token->revoked_at) {
            return;
        }

        $token->forceFill([
            'revoked_at' => now(),
        ])->save();

        self::logTokenEvent('maintenance_token_revoked', $token, $actorId, [
            'token_id' => $token->getKey(),
        ]);
    }

    public static function verify(string $plain): ?MaintenanceToken
    {
        $plain = self::normalizeToken($plain);
        if (! $plain) {
            return null;
        }

        try {
            $tokens = MaintenanceToken::query()
                ->whereNull('revoked_at')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now());
                })
                ->get();
        } catch (\Throwable $e) {
            Log::warning('maintenance_tokens.verify.failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        foreach ($tokens as $token) {
            if (Hash::check($plain, $token->token_hash)) {
                $token->forceFill(['last_used_at' => now()])->save();
                return $token;
            }
        }

        return null;
    }

    public static function normalizeToken(?string $plain): ?string
    {
        if (! is_string($plain)) {
            return null;
        }

        $clean = trim($plain);
        if ($clean === '') {
            return null;
        }

        $clean = preg_replace('/\s+/', '', $clean) ?? '';
        $clean = strtoupper($clean);

        return $clean !== '' ? $clean : null;
    }

    private static function generateToken(): string
    {
        return Str::upper(Str::random(10)) . '-' . Str::upper(Str::random(10)) . '-' . Str::upper(Str::random(10));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function logTokenEvent(string $action, MaintenanceToken $token, ?int $actorId, array $context): void
    {
        AuditLogWriter::writeAudit([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => MaintenanceToken::class,
            'auditable_id' => $token->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'route' => request()?->route()?->getName(),
            'method' => request()?->method(),
            'status_code' => null,
            'request_id' => request()?->headers->get('X-Request-Id'),
            'session_id' => request()?->hasSession() ? request()?->session()->getId() : null,
            'duration_ms' => null,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}

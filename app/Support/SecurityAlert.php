<?php

namespace App\Support;

use App\Jobs\SendSecurityAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityAlert
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function dispatch(string $event, array $context = [], ?Request $request = null): void
    {
        if (! config('security.alert_enabled', true)) {
            return;
        }

        $request = $request ?: request();
        $user = $request?->user();
        $contextIdentity = self::extractIdentity($context);
        $contextUsername = self::normalizeString($context['username'] ?? null);
        $contextEmail = self::normalizeString($context['email'] ?? null);
        $contextUserId = $context['user_id'] ?? null;

        $ipObserved = $request?->ip();
        $forwardedFor = $request?->header('x-forwarded-for');
        $forwardedIps = self::parseForwardedFor($forwardedFor);
        [$ipPublic, $ipPrivate] = self::resolveIpAddresses($ipObserved, $forwardedIps);

        $payload = [
            'event' => $event,
            'title' => $context['title'] ?? $event,
            'user_id' => $user?->getAuthIdentifier() ?? (is_int($contextUserId) ? $contextUserId : null),
            'username' => $user?->username ?? $contextUsername,
            'email' => $user?->email ?? $contextEmail,
            'identity' => $contextIdentity,
            'ip_observed' => $ipObserved,
            'ip_public' => $ipPublic,
            'ip_private' => $ipPrivate,
            'proxy_chain' => $forwardedFor,
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 180) : null,
            'method' => $request?->method(),
            'path' => $request?->path(),
            'request_id' => $request?->headers->get('X-Request-Id'),
            'timestamp' => now()->toIso8601String(),
            'context' => $context,
        ];

        $channel = (string) config('security.alert_log_channel', 'security');
        $contextKeys = array_values(array_filter(array_map('strval', array_keys($context))));

        try {
            Log::channel($channel)->info('security.alert.dispatched', [
                'event' => $event,
                'title' => $payload['title'],
                'user_id' => $payload['user_id'],
                'identity' => $payload['identity'],
                'ip_observed' => $payload['ip_observed'],
                'path' => $payload['path'],
                'method' => $payload['method'],
                'request_id' => $payload['request_id'],
                'context_keys' => $contextKeys,
            ]);
        } catch (\Throwable) {
            Log::info('security.alert.dispatched', [
                'event' => $event,
                'title' => $payload['title'],
                'user_id' => $payload['user_id'],
                'identity' => $payload['identity'],
                'ip_observed' => $payload['ip_observed'],
                'path' => $payload['path'],
                'method' => $payload['method'],
                'request_id' => $payload['request_id'],
                'context_keys' => $contextKeys,
            ]);
        }

        SendSecurityAlert::dispatch($payload)->onQueue('alerts');
    }

    private static function isPrivateIp(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        if (self::isPublicIp($ip)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private static function isPublicIp(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return array<int, string>
     */
    private static function parseForwardedFor(?string $header): array
    {
        if (! is_string($header) || trim($header) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }

    /**
     * @param  array<int, string>  $forwardedIps
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveIpAddresses(?string $observed, array $forwardedIps): array
    {
        $public = null;
        $private = null;

        foreach ($forwardedIps as $candidate) {
            if (! $public && self::isPublicIp($candidate)) {
                $public = $candidate;
            }

            if (! $private && self::isPrivateIp($candidate)) {
                $private = $candidate;
            }
        }

        if (! $public && self::isPublicIp($observed)) {
            $public = $observed;
        }

        if (! $private && self::isPrivateIp($observed)) {
            $private = $observed;
        }

        return [$public, $private];
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function extractIdentity(array $context): ?string
    {
        $identity = self::normalizeString($context['identity'] ?? null);
        if ($identity) {
            return $identity;
        }

        return self::normalizeString($context['username'] ?? null)
            ?? self::normalizeString($context['email'] ?? null);
    }
}

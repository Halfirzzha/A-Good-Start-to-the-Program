<?php

namespace App\Http\Middleware;

use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use App\Support\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    private const MAX_PAYLOAD_DEPTH = 5;
    private const MAX_PAYLOAD_ITEMS = 50;
    private const MAX_STRING_LENGTH = 500;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $requestId = SecurityService::requestId($request);
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $user = $request->user();

        $threatConfig = $this->threatConfig();
        if ($threatConfig['enabled'] && ! $this->shouldIgnoreThreats($request)) {
            $blockResponse = $this->enforceThreatBlocks($request, $user, $requestId, $sessionId, $threatConfig);
            if ($blockResponse) {
                return $blockResponse;
            }
        }

        $response = $next($request);

        $statusCode = $response->getStatusCode();
        $shouldLog = $this->shouldLog($request) || $this->shouldLogError($statusCode);

        if ($threatConfig['enabled'] && ! $this->shouldIgnoreThreats($request)) {
            $this->evaluateThreats($request, $response, $user, $requestId, $sessionId, $threatConfig);
        }

        if (! $shouldLog) {
            return $response;
        }

        AuditLogWriter::writeAudit([
            'user_id' => $user?->getAuthIdentifier(),
            'action' => 'http_request',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $this->truncate((string) $request->userAgent(), 255),
            'url' => $this->truncate($request->fullUrl(), 2000),
            'route' => $this->truncate((string) optional($request->route())->getName(), 255),
            'method' => $request->getMethod(),
            'status_code' => $statusCode,
            'request_id' => $requestId,
            'session_id' => $this->truncate((string) $sessionId, 100),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'context' => [
                'query' => $request->query(),
                'payload' => $this->sanitizePayload($request),
                'headers' => $this->filterHeaders($request->headers->all()),
            ],
            'created_at' => now(),
        ]);

        return $response;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function enforceThreatBlocks(
        Request $request,
        mixed $user,
        string $requestId,
        ?string $sessionId,
        array $config
    ): ?Response {
        $cache = $this->cacheStore($config);
        $ip = (string) $request->ip();
        $userId = $user?->getAuthIdentifier();
        $isDeveloper = $user && method_exists($user, 'isDeveloper') && $user->isDeveloper();

        // Check SecurityService blocklist first
        $globalBlocked = SecurityService::isIpBlocked($ip);

        $blockedIp = $globalBlocked || ($ip !== '' && $cache->has($this->cacheKey('block:ip', $ip)));
        $blockedUser = $userId && $cache->has($this->cacheKey('block:user', (string) $userId));

        if (! $blockedIp && ! $blockedUser) {
            return null;
        }

        $context = [
            'reason' => $blockedUser ? 'user_blocked' : 'ip_blocked',
            'blocked_user' => (bool) $blockedUser,
            'blocked_ip' => $blockedIp,
            'developer_exempt' => $isDeveloper,
        ];

        $this->writeSecurityEvent(
            $request,
            $user,
            $requestId,
            $sessionId,
            403,
            'security_blocked_request',
            $context
        );

        if ($isDeveloper) {
            return null;
        }

        return response()->view('errors.error', [
            'exception' => null,
            'statusCode' => $blockedIp ? 429 : 403,
            'requestId' => $requestId,
        ], $blockedIp ? 429 : 403);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function evaluateThreats(
        Request $request,
        Response $response,
        mixed $user,
        string $requestId,
        ?string $sessionId,
        array $config
    ): void {
        $statusCode = $response->getStatusCode();
        $points = (int) ($config['status_points'][$statusCode] ?? 0);
        $payload = $this->sanitizePayload($request);
        [$signalPoints, $signals, $signalMeta] = $this->collectThreatSignals($request, $payload, $config);
        $points += $signalPoints;

        $adminPath = trim((string) config('audit.admin_path', 'admin'), '/');
        if ($adminPath !== '' && $request->is($adminPath.'*')) {
            $points += (int) ($config['admin_path_bonus'] ?? 0);
        }

        if ($this->isAuthPath($request)) {
            $points += (int) ($config['auth_path_bonus'] ?? 0);
        }

        $cache = $this->cacheStore($config);
        $burstWindow = max(10, (int) ($config['burst_window_seconds'] ?? 60));
        $burstLimit = max(10, (int) ($config['burst_requests'] ?? 120));
        $bursted = false;
        $burstCount = $this->incrementBurstCounter($cache, $request, $user, $burstWindow);
        if ($burstCount >= $burstLimit) {
            $points += 4;
            $bursted = true;
        }

        if ($points <= 0) {
            return;
        }

        $riskDecay = max(5, (int) ($config['risk_decay_minutes'] ?? 30));
        $threshold = max(1, (int) ($config['risk_threshold'] ?? 10));
        $riskExpiry = now()->addMinutes($riskDecay);

        $ip = (string) $request->ip();
        $userId = $user?->getAuthIdentifier();
        $isDeveloper = $user && method_exists($user, 'isDeveloper') && $user->isDeveloper();

        $score = $points;
        if ($userId) {
            $score = $this->incrementRiskScore(
                $cache,
                $this->cacheKey('risk:user', (string) $userId),
                $points,
                $riskExpiry
            );
        } elseif ($ip !== '') {
            $score = $this->incrementRiskScore(
                $cache,
                $this->cacheKey('risk:ip', $ip),
                $points,
                $riskExpiry
            );
        }

        $context = [
            'score' => $score,
            'points' => $points,
            'threshold' => $threshold,
            'status_code' => $statusCode,
            'burst_count' => $burstCount,
            'burst_limit' => $burstLimit,
            'burst_window_seconds' => $burstWindow,
            'developer_exempt' => $isDeveloper,
            'admin_path' => $adminPath,
        ];
        if (! empty($signals)) {
            $context['signals'] = $signals;
        }
        if (! empty($signalMeta)) {
            $context['signal_meta'] = $signalMeta;
        }
        if ($bursted) {
            $context['burst_triggered'] = true;
        }

        $this->writeSecurityEvent(
            $request,
            $user,
            $requestId,
            $sessionId,
            $statusCode,
            'security_risk',
            $context,
            $payload
        );

        if ($score < $threshold || ! ($config['auto_block'] ?? true)) {
            return;
        }

        $blockContext = [
            ...$context,
            'auto_block' => true,
        ];

        if ($isDeveloper) {
            $this->triggerAlert('Security alert (developer exempt)', $request, $user, $blockContext, $config);
            return;
        }

        $blockUntil = now()->addMinutes(max(1, (int) ($config['user_block_minutes'] ?? 60)));
        $blocked = false;
        $sessionRevoked = false;

        if ($userId && $user && method_exists($user, 'forceFill')) {
            $user->forceFill([
                'locked_at' => now(),
                'blocked_until' => $blockUntil,
                'blocked_reason' => 'auto_security_block',
                'blocked_by' => null,
            ])->save();
            $cache->put($this->cacheKey('block:user', (string) $userId), true, $blockUntil);
            $blocked = true;

            if (method_exists($user, 'rotateSecurityStamp')) {
                $user->rotateSecurityStamp();
                $sessionRevoked = true;
            }

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $sessionRevoked = true;
            }
        } elseif ($ip !== '') {
            $ipBlock = now()->addMinutes(max(1, (int) ($config['ip_block_minutes'] ?? 30)));
            $cache->put($this->cacheKey('block:ip', $ip), true, $ipBlock);
            $blocked = true;
            $blockContext['ip_block_until'] = $ipBlock->toIso8601String();
        }

        $blockContext['blocked'] = $blocked;
        $blockContext['session_revoked'] = $sessionRevoked;

        $this->writeSecurityEvent(
            $request,
            $user,
            $requestId,
            $sessionId,
            403,
            'security_blocked',
            $blockContext,
            $payload
        );

        $this->triggerAlert('Security alert (auto-block)', $request, $user, $blockContext, $config);
    }

    private function threatConfig(): array
    {
        $config = config('security.threat_detection', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'aggressive' => (bool) ($config['aggressive'] ?? false),
            'cache_store' => (string) ($config['cache_store'] ?? 'rate_limit'),
            'risk_threshold' => (int) ($config['risk_threshold'] ?? 10),
            'risk_decay_minutes' => (int) ($config['risk_decay_minutes'] ?? 30),
            'burst_requests' => (int) ($config['burst_requests'] ?? 120),
            'burst_window_seconds' => (int) ($config['burst_window_seconds'] ?? 60),
            'admin_path_bonus' => (int) ($config['admin_path_bonus'] ?? 0),
            'auth_path_bonus' => (int) ($config['auth_path_bonus'] ?? 0),
            'status_points' => (array) ($config['status_points'] ?? []),
            'suspicious_methods' => (array) ($config['suspicious_methods'] ?? []),
            'method_points' => (int) ($config['method_points'] ?? 0),
            'missing_user_agent_points' => (int) ($config['missing_user_agent_points'] ?? 0),
            'short_user_agent_points' => (int) ($config['short_user_agent_points'] ?? 0),
            'user_agent_patterns' => (array) ($config['user_agent_patterns'] ?? []),
            'user_agent_pattern_points' => (int) ($config['user_agent_pattern_points'] ?? 0),
            'max_forwarded_for' => (int) ($config['max_forwarded_for'] ?? 0),
            'forwarded_for_points' => (int) ($config['forwarded_for_points'] ?? 0),
            'max_query_length' => (int) ($config['max_query_length'] ?? 0),
            'query_length_points' => (int) ($config['query_length_points'] ?? 0),
            'max_payload_kb' => (int) ($config['max_payload_kb'] ?? 0),
            'payload_size_points' => (int) ($config['payload_size_points'] ?? 0),
            'path_patterns' => (array) ($config['path_patterns'] ?? []),
            'path_pattern_points' => (int) ($config['path_pattern_points'] ?? 0),
            'payload_patterns' => (array) ($config['payload_patterns'] ?? []),
            'payload_pattern_points' => (int) ($config['payload_pattern_points'] ?? 0),
            'auto_block' => (bool) ($config['auto_block'] ?? true),
            'user_block_minutes' => (int) ($config['user_block_minutes'] ?? 60),
            'ip_block_minutes' => (int) ($config['ip_block_minutes'] ?? 30),
            'alert' => (array) ($config['alert'] ?? []),
        ];
    }

    private function shouldIgnoreThreats(Request $request): bool
    {
        // Always ignore Livewire requests - they generate many AJAX calls
        if ($request->is('livewire/*') || $request->is('livewire/update')) {
            return true;
        }

        $ignore = config('audit.ignore_paths', []);
        return ! empty($ignore) && $request->is($ignore);
    }

    private function isAuthPath(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        $authHints = [
            '/login',
            '/logout',
            '/password',
            '/password-reset',
            '/forgot-password',
            '/reset-password',
            '/admin/login',
        ];

        foreach ($authHints as $hint) {
            if (str_starts_with($path, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function cacheStore(array $config)
    {
        $store = $config['cache_store'] ?? null;

        try {
            return $store ? Cache::store((string) $store) : Cache::store();
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    private function cacheKey(string $scope, string $value): string
    {
        // Use SHA256 for secure hashing of sensitive values like IP addresses
        $safe = str_contains($scope, 'ip') ? hash('sha256', $value) : $value;

        return "security:{$scope}:{$safe}";
    }

    private function incrementBurstCounter($cache, Request $request, mixed $user, int $windowSeconds): int
    {
        $ip = (string) $request->ip();
        $userId = $user?->getAuthIdentifier();
        $key = $userId
            ? $this->cacheKey('burst:user', (string) $userId)
            : $this->cacheKey('burst:ip', $ip);

        try {
            $cache->add($key, 0, now()->addSeconds($windowSeconds));
            return (int) $cache->increment($key);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function incrementRiskScore($cache, string $key, int $points, \DateTimeInterface $expiresAt): int
    {
        try {
            $cache->add($key, 0, $expiresAt);
            return (int) $cache->increment($key, $points);
        } catch (\Throwable) {
            return $points;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @return array{0:int,1:array<int,string>,2:array<string,mixed>}
     */
    private function collectThreatSignals(Request $request, array $payload, array $config): array
    {
        $signals = [];
        $meta = [];
        $points = 0;

        $method = strtoupper($request->getMethod());
        $suspiciousMethods = array_map('strtoupper', $config['suspicious_methods'] ?? []);
        if (! empty($suspiciousMethods) && in_array($method, $suspiciousMethods, true)) {
            $points += (int) ($config['method_points'] ?? 0);
            $signals[] = "method:{$method}";
        }

        $userAgent = trim((string) $request->userAgent());
        if ($userAgent === '') {
            $points += (int) ($config['missing_user_agent_points'] ?? 0);
            $signals[] = 'missing_user_agent';
        } elseif (strlen($userAgent) < 12) {
            $points += (int) ($config['short_user_agent_points'] ?? 0);
            $signals[] = 'short_user_agent';
        }

        $uaMatches = $this->matchPatterns($userAgent, $config['user_agent_patterns'] ?? []);
        if (! empty($uaMatches)) {
            $points += (int) ($config['user_agent_pattern_points'] ?? 0) * count($uaMatches);
            $signals[] = 'user_agent:' . implode(',', $uaMatches);
            $meta['user_agent_matches'] = $uaMatches;
        }

        $query = (string) $request->getQueryString();
        $maxQuery = (int) ($config['max_query_length'] ?? 0);
        if ($maxQuery > 0 && $query !== '' && strlen($query) > $maxQuery) {
            $points += (int) ($config['query_length_points'] ?? 0);
            $signals[] = 'long_query';
            $meta['query_length'] = strlen($query);
        }

        $xff = (string) $request->headers->get('x-forwarded-for', '');
        if ($xff !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $xff))));
            $maxForward = (int) ($config['max_forwarded_for'] ?? 0);
            if ($maxForward > 0 && count($parts) > $maxForward) {
                $points += (int) ($config['forwarded_for_points'] ?? 0);
                $signals[] = 'forwarded_chain';
                $meta['forwarded_for_count'] = count($parts);
            }
        }

        $path = '/'.ltrim($request->path(), '/');
        $pathMatches = $this->matchPatterns($path.'?'.$query, $config['path_patterns'] ?? []);
        if (! empty($pathMatches)) {
            $points += (int) ($config['path_pattern_points'] ?? 0) * count($pathMatches);
            $signals[] = 'path:' . implode(',', $pathMatches);
            $meta['path_matches'] = $pathMatches;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($payloadJson)) {
            $payloadKb = (int) ceil(strlen($payloadJson) / 1024);
            $maxPayloadKb = (int) ($config['max_payload_kb'] ?? 0);
            if ($maxPayloadKb > 0 && $payloadKb > $maxPayloadKb) {
                $points += (int) ($config['payload_size_points'] ?? 0);
                $signals[] = 'payload_size';
                $meta['payload_kb'] = $payloadKb;
            }

            $payloadMatches = $this->matchPatterns($payloadJson, $config['payload_patterns'] ?? []);
            if (! empty($payloadMatches)) {
                $points += (int) ($config['payload_pattern_points'] ?? 0) * count($payloadMatches);
                $signals[] = 'payload:' . implode(',', $payloadMatches);
                $meta['payload_matches'] = $payloadMatches;
            }
        }

        return [$points, $signals, $meta];
    }

    /**
     * @param array<string, string> $patterns
     * @return array<int, string>
     */
    private function matchPatterns(string $input, array $patterns): array
    {
        if ($input === '' || empty($patterns)) {
            return [];
        }

        $matches = [];
        foreach ($patterns as $name => $pattern) {
            if ($pattern && @preg_match($pattern, $input)) {
                $matches[] = is_string($name) ? $name : (string) $pattern;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeSecurityEvent(
        Request $request,
        mixed $user,
        string $requestId,
        ?string $sessionId,
        int $statusCode,
        string $action,
        array $context,
        ?array $payload = null
    ): void {
        AuditLogWriter::writeAudit([
            'user_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $this->truncate((string) $request->userAgent(), 255),
            'url' => $this->truncate($request->fullUrl(), 2000),
            'route' => $this->truncate((string) optional($request->route())->getName(), 255),
            'method' => $request->getMethod(),
            'status_code' => $statusCode,
            'request_id' => $requestId,
            'session_id' => $this->truncate((string) $sessionId, 100),
            'duration_ms' => null,
            'context' => [
                'path' => '/'.ltrim($request->path(), '/'),
                'payload' => $payload ?? $this->sanitizePayload($request),
                ...$context,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $config
     */
    private function triggerAlert(
        string $title,
        Request $request,
        mixed $user,
        array $context,
        array $config
    ): void {
        $alert = $config['alert'] ?? [];
        if (! ($alert['enabled'] ?? false)) {
            return;
        }

        $channel = (string) ($alert['log_channel'] ?? 'security');
        $payload = [
            'title' => $title,
            'user_id' => $user?->getAuthIdentifier(),
            'ip_address' => $request->ip(),
            'path' => '/'.ltrim($request->path(), '/'),
            'method' => $request->method(),
            'context' => $context,
        ];

        try {
            Log::channel($channel)->warning($title, $payload);
        } catch (\Throwable) {
            Log::warning($title, $payload);
        }

        SecurityAlert::dispatch('security_alert', [
            'title' => $title,
            'details' => $context,
        ], $request);
    }

    private function shouldLog(Request $request): bool
    {
        if (! config('audit.enabled', true)) {
            return false;
        }

        if ($this->isStreamingRequest($request)) {
            return false;
        }

        $ignore = config('audit.ignore_paths', []);
        if (! empty($ignore) && $request->is($ignore)) {
            return false;
        }

        $adminPath = trim((string) config('audit.admin_path', 'admin'), '/');
        if ($adminPath !== '' && $request->is($adminPath.'*')) {
            return (bool) config('audit.log_admin', true);
        }

        $methods = array_map('strtoupper', config('audit.http_methods', ['POST', 'PUT', 'PATCH', 'DELETE']));
        return in_array(strtoupper($request->getMethod()), $methods, true);
    }

    private function shouldLogError(int $statusCode): bool
    {
        if (! config('audit.log_errors', true)) {
            return false;
        }

        $minStatus = (int) config('audit.error_min_status', 400);

        return $statusCode >= $minStatus;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizePayload(Request $request): array
    {
        $input = $request->all();
        $sensitiveKeys = $this->sensitiveKeys();

        return $this->sanitizeArray($input, $sensitiveKeys, 0);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $sensitiveKeys
     * @param  int  $depth
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $input, array $sensitiveKeys, int $depth): array
    {
        $sanitized = [];
        $count = 0;

        foreach ($input as $key => $value) {
            if ($count >= self::MAX_PAYLOAD_ITEMS) {
                $sanitized['__truncated__'] = 'Payload truncated (too many items)';
                break;
            }

            $count++;

            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            if ($value instanceof UploadedFile) {
                $sanitized[$key] = $value->getClientOriginalName();
                continue;
            }

            if (is_array($value)) {
                if ($depth >= self::MAX_PAYLOAD_DEPTH) {
                    $sanitized[$key] = '[truncated]';
                    continue;
                }

                $sanitized[$key] = $this->sanitizeArray($value, $sensitiveKeys, $depth + 1);
                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                $sanitized[$key] = substr($value, 0, self::MAX_STRING_LENGTH).'…';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function filterHeaders(array $headers): array
    {
        $allowlist = array_map('strtolower', config('audit.header_allowlist', []));
        $filtered = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (! in_array($lower, $allowlist, true)) {
                continue;
            }

            $filtered[$lower] = $this->truncate(implode(', ', $values), 500);
        }

        return $filtered;
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }

    /**
     * @return list<string>
     */
    private function sensitiveKeys(): array
    {
        return array_map('strtolower', config('audit.sensitive_keys', []));
    }

    private function isStreamingRequest(Request $request): bool
    {
        if ($request->headers->get('Accept') === 'text/event-stream') {
            return true;
        }

        return $request->is('maintenance/stream');
    }
}

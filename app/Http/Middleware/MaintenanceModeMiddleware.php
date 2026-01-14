<?php

namespace App\Http\Middleware;

use App\Support\AuditLogWriter;
use App\Support\MaintenanceService;
use App\Support\MaintenanceTokenService;
use App\Support\SecurityAlert;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceModeMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = MaintenanceService::getSettings();
        $snapshot = MaintenanceService::snapshot($maintenance);

        if (! $snapshot['is_active']) {
            return $next($request);
        }

        if ($this->isBypassed($request, $maintenance)) {
            return $next($request);
        }

        if ($this->isExplicitlyAllowed($request, $maintenance)) {
            return $next($request);
        }

        if ($this->isAllowedByMode($request, $maintenance)) {
            return $next($request);
        }

        $requestId = $request->headers->get('X-Request-Id');
        $retryAfter = $snapshot['retry_after'];
        $noteHtml = $maintenance['note_html'] ?? ($maintenance['note'] ?? null);

        $payload = [
            'statusCode' => 503,
            'requestId' => $requestId,
            'serverNow' => now()->toIso8601String(),
            'maintenanceData' => [
                'start_at' => $maintenance['start_at'] ?? null,
                'end_at' => $maintenance['end_at'] ?? null,
                'note_html' => $noteHtml,
                'retry' => $retryAfter,
                'title' => $maintenance['title'] ?? null,
                'summary' => $maintenance['summary'] ?? null,
            ],
        ];

        $this->logMaintenanceBlocked($request, $maintenance);

        $response = response()->view('errors.maintenance', $payload, 503);

        if ($retryAfter) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('X-DNS-Prefetch-Control', 'off');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; worker-src 'self' blob:; connect-src 'self'; frame-ancestors 'none';"
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function isBypassed(Request $request, array $maintenance): bool
    {
        $user = $request->user();
        if ($user && method_exists($user, 'isDeveloper') && $user->isDeveloper()
            && (bool) config('security.developer_bypass_validations', false)
            && (bool) ($maintenance['allow_developer_bypass'] ?? false)) {
            $this->logMaintenanceBypass($request, 'developer', true, true);
            return true;
        }

        $roles = array_filter((array) ($maintenance['allow_roles'] ?? []));
        if ($user && ! empty($roles) && method_exists($user, 'hasRole') && $user->hasRole($roles)) {
            $this->logMaintenanceBypass($request, 'role', true, true);
            return true;
        }

        $allowIps = $this->normalizeList($maintenance['allow_ips'] ?? []);
        if ($this->ipIsAllowed($request->ip(), $allowIps)) {
            $this->logMaintenanceBypass($request, 'ip', true, true);
            return true;
        }

        if ($request->hasSession() && $request->session()->get('maintenance_bypass') === true) {
            return true;
        }

        $token = $request->input('maintenance_token')
            ?? $request->header('X-Maintenance-Token')
            ?? $request->query('maintenance_token');

        $token = MaintenanceTokenService::normalizeToken(is_string($token) ? $token : null);
        if (! $token) {
            return false;
        }

        $verified = MaintenanceTokenService::verify($token);
        if ($verified) {
            if ($request->hasSession()) {
                $request->session()->put('maintenance_bypass', true);
            }

            $this->logMaintenanceBypass($request, 'token', true);
            return true;
        }

        $this->logMaintenanceBypass($request, 'token_failed', false);

        return false;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function isExplicitlyAllowed(Request $request, array $maintenance): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        $routeName = $request->route()?->getName();

        $allowPaths = $this->normalizeList($maintenance['allow_paths'] ?? []);
        $allowRoutes = $this->normalizeList($maintenance['allow_routes'] ?? []);

        if ($this->matchesAny($path, $allowPaths)) {
            return true;
        }

        if ($routeName && $this->matchesAny($routeName, $allowRoutes)) {
            return true;
        }

        if ((bool) ($maintenance['allow_api'] ?? false)) {
            if ($request->expectsJson() || Str::startsWith($path, '/api/')) {
                return true;
            }
        }

        if (str_starts_with($path, '/assets/maintenance/')) {
            return true;
        }

        if (in_array($path, ['/favicon.ico', '/maintenance/bypass', '/maintenance/status', '/maintenance/stream', '/health/check', '/health/dashboard', '/up'], true)) {
            return true;
        }

        if ($routeName === 'health.dashboard') {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function isAllowedByMode(Request $request, array $maintenance): bool
    {
        $mode = (string) ($maintenance['mode'] ?? 'global');
        $path = '/'.ltrim($request->path(), '/');
        $routeName = $request->route()?->getName();

        $denyPaths = $this->normalizeList($maintenance['deny_paths'] ?? []);
        $denyRoutes = $this->normalizeList($maintenance['deny_routes'] ?? []);

        if ($mode === 'denylist') {
            $denied = $this->matchesAny($path, $denyPaths) || ($routeName && $this->matchesAny($routeName, $denyRoutes));
            return ! $denied;
        }

        if ($mode === 'allowlist') {
            $allowPaths = $this->normalizeList($maintenance['allow_paths'] ?? []);
            $allowRoutes = $this->normalizeList($maintenance['allow_routes'] ?? []);

            return $this->matchesAny($path, $allowPaths) || ($routeName && $this->matchesAny($routeName, $allowRoutes));
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function parseDate(mixed $value): ?Carbon
    {
        return MaintenanceService::parseDate($value);
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function logMaintenanceBlocked(Request $request, array $maintenance): void
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => 'maintenance_blocked',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'url' => $request->fullUrl(),
            'route' => (string) optional($request->route())->getName(),
            'method' => $request->getMethod(),
            'status_code' => 503,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'mode' => $maintenance['mode'] ?? null,
                'window_start' => $maintenance['start_at'] ?? null,
                'window_end' => $maintenance['end_at'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }

    private function logMaintenanceBypass(Request $request, string $reason, bool $granted, bool $oncePerSession = false): void
    {
        if ($oncePerSession && $request->hasSession()) {
            $key = 'maintenance_bypass_logged_'.$reason;
            if ($request->session()->get($key) === true) {
                return;
            }
            $request->session()->put($key, true);
        }

        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $action = $granted ? 'maintenance_bypass_granted' : 'maintenance_bypass_denied';

        AuditLogWriter::writeAudit([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'url' => $request->fullUrl(),
            'route' => (string) optional($request->route())->getName(),
            'method' => $request->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'reason' => $reason,
                'granted' => $granted,
            ],
            'created_at' => now(),
        ]);

        if (! $request->is('maintenance/bypass')) {
            $event = $granted ? 'maintenance_bypass_granted' : 'maintenance_bypass_denied';
            SecurityAlert::dispatch($event, [
                'title' => $granted ? 'Maintenance bypass granted' : 'Maintenance bypass denied',
                'reason' => $reason,
            ], $request);
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function normalizeList(array $items): array
    {
        return array_values(array_filter(array_map(function ($item): string {
            return trim((string) $item);
        }, $items)));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            $normalized = Str::startsWith($pattern, '/') ? $pattern : '/'.$pattern;
            if (Str::is($normalized, Str::startsWith($value, '/') ? $value : '/'.$value)) {
                return true;
            }

            if (Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $allowIps
     */
    private function ipIsAllowed(?string $ip, array $allowIps): bool
    {
        if (! is_string($ip) || $ip === '' || $allowIps === []) {
            return false;
        }

        foreach ($allowIps as $rule) {
            if ($rule === $ip) {
                return true;
            }

            if (str_contains($rule, '/') && $this->ipInCidr($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! $subnet || ! is_numeric($bits)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bits = (int) $bits;
        $length = strlen($subnetBin) * 8;

        if ($bits < 0 || $bits > $length) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        for ($i = 0; $i < strlen($subnetBin); $i++) {
            $mask = 0;

            if ($i < $fullBytes) {
                $mask = 0xFF;
            } elseif ($i === $fullBytes && $remainingBits > 0) {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            }

            if ((ord($ipBin[$i]) & $mask) !== (ord($subnetBin[$i]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}

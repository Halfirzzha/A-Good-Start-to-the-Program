<?php

namespace App\Http\Middleware;

use App\Support\AuditLogWriter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $requiredPermission = 'access_admin_panel';
        if ($user->can($requiredPermission)) {
            return $next($request);
        }

        if ($this->shouldBypass($user)) {
            $this->logBypassed($request, $user, $requiredPermission);
            return $next($request);
        }

        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $user->getAuthIdentifier(),
            'identity' => $user->email ?? $user->username,
            'event' => 'admin_access_denied',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'required_permission' => $requiredPermission,
                'roles' => $user->getRoleNames(),
            ],
            'created_at' => now(),
        ]);

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, 'Access denied.');
    }

    private function shouldBypass(mixed $user): bool
    {
        return $user && method_exists($user, 'isDeveloper')
            && $user->isDeveloper()
            && (bool) config('security.developer_bypass_validations', false);
    }

    private function logBypassed(Request $request, mixed $user, string $requiredPermission): void
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $user?->getAuthIdentifier(),
            'identity' => $user?->email ?? $user?->username,
            'event' => 'admin_access_bypassed',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'required_permission' => $requiredPermission,
                'roles' => $user?->getRoleNames(),
            ],
            'created_at' => now(),
        ]);
    }
}

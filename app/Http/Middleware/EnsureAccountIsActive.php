<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Support\AuditLogWriter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enforce_account_status', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $reasons = [];
        $developerBypass = $this->shouldBypass($user);

        if (config('security.enforce_email_verification', true) && method_exists($user, 'hasVerifiedEmail')) {
            if (! $user->hasVerifiedEmail()) {
                $reasons[] = 'email_unverified';
            }
        }

        if (config('security.enforce_username', true) && blank($user->username)) {
            $reasons[] = 'username_missing';
        }

        if ($user->must_change_password ?? false) {
            $reasons[] = 'password_change_required';
        }

        $passwordExpiresAt = $user->password_expires_at ?? null;
        if ($passwordExpiresAt && $passwordExpiresAt->isPast()) {
            $reasons[] = 'password_expired';
            if (! ($user->must_change_password ?? false)) {
                $user->forceFill([
                    'must_change_password' => true,
                ])->save();
            }
        }

        $blockedUntil = $user->blocked_until;
        $isTemporarilyBlocked = $blockedUntil && $blockedUntil->isFuture();
        $isLocked = ! empty($user->locked_at);
        $isInactive = $user->account_status !== AccountStatus::Active;

        if (! $isTemporarilyBlocked && $isLocked && $blockedUntil && $blockedUntil->isPast() && ! $isInactive) {
            $user->forceFill([
                'locked_at' => null,
                'blocked_until' => null,
                'failed_login_attempts' => 0,
            ])->save();

            return $next($request);
        }

        if ($isTemporarilyBlocked) {
            $reasons[] = 'blocked_until';
        }

        if ($isLocked) {
            $reasons[] = 'locked';
        }

        if ($isInactive) {
            $reasons[] = 'account_inactive';
        }

        if (empty($reasons)) {
            return $next($request);
        }

        if ($developerBypass) {
            $this->logBypassed($request, $user, $reasons);
            return $next($request);
        }

        $this->logDenied($request, $user, 'account_blocked_access', $reasons);

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, 'Account is not active.');
    }

    private function logDenied(Request $request, mixed $user, string $reason, array $reasons = []): void
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $user?->getAuthIdentifier(),
            'identity' => $user?->email ?? $user?->username,
            'event' => 'account_blocked_access',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'status' => $user?->account_status,
                'locked_at' => $user?->locked_at,
                'blocked_until' => $user?->blocked_until,
                'reason' => $reason,
                'reasons' => $reasons,
            ],
            'created_at' => now(),
        ]);
    }

    private function logBypassed(Request $request, mixed $user, array $reasons): void
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $user?->getAuthIdentifier(),
            'identity' => $user?->email ?? $user?->username,
            'event' => 'account_check_bypassed',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'status' => $user?->account_status,
                'locked_at' => $user?->locked_at,
                'blocked_until' => $user?->blocked_until,
                'reasons' => $reasons,
            ],
            'created_at' => now(),
        ]);
    }

    private function shouldBypass(mixed $user): bool
    {
        return $user && method_exists($user, 'isDeveloper')
            && $user->isDeveloper()
            && (bool) config('security.developer_bypass_validations', false);
    }
}

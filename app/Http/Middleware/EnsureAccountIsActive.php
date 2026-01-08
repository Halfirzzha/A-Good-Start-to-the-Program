<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Support\AuditLogWriter;
use App\Support\NotificationDeliveryLogger;
use App\Support\SecurityAlert;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
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

            $autoReset = (bool) config('security.password_auto_reset_on_expiry', true);
            $resetKey = 'password_expired_reset:' . $user->getAuthIdentifier() . ':' . $passwordExpiresAt->toDateString();
            if ($autoReset && Cache::add($resetKey, true, now()->addDay())) {
                $expiryDays = max(1, (int) config('security.password_expiry_days', 90));
                $temporaryPassword = Str::random(16);

                $user->forceFill([
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                    'password_expires_at' => now()->addDays($expiryDays),
                ])->save();

                $this->notifyPasswordExpiry($request, $user, true, $temporaryPassword);
                $this->auditPasswordReset($request, $user, 'password_auto_reset');
            }
        } elseif ($passwordExpiresAt && $passwordExpiresAt->isFuture()) {
            $notifyDays = max(1, (int) config('security.password_expiry_notify_days', 7));
            if ($passwordExpiresAt->diffInDays(now()) <= $notifyDays) {
                $noticeKey = 'password_expiry_notice:' . $user->getAuthIdentifier() . ':' . $passwordExpiresAt->toDateString();
                if (Cache::add($noticeKey, true, now()->addDay())) {
                    $this->notifyPasswordExpiry($request, $user, false, null);
                }
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

    private function notifyPasswordExpiry(Request $request, mixed $user, bool $wasReset, ?string $temporaryPassword): void
    {
        $message = $wasReset
            ? 'Password telah kedaluwarsa dan dibuat ulang otomatis. Segera ganti password Anda.'
            : 'Password Anda akan kedaluwarsa segera. Harap ganti password untuk keamanan.';

        if ($user?->email) {
            try {
                $body = $message;
                if ($wasReset && $temporaryPassword) {
                    $body .= "\n\nTemporary password: {$temporaryPassword}\nSegera ganti setelah login.";
                }
                $fromAddress = (string) \App\Support\SystemSettings::getValue('notifications.email.auth_from_address', '')
                    ?: (string) \App\Support\SystemSettings::getValue('notifications.email.from_address', '');
                $fromName = (string) \App\Support\SystemSettings::getValue('notifications.email.auth_from_name', '')
                    ?: (string) \App\Support\SystemSettings::getValue('notifications.email.from_name', '');
                \App\Support\SystemSettings::applyMailConfig('auth');
                Mail::raw($body, function ($mail) use ($user, $fromAddress, $fromName): void {
                    $mail->to($user->email)->subject('Password Expiry Notice');
                    if ($fromAddress !== '') {
                        $mail->from($fromAddress, $fromName !== '' ? $fromName : null);
                    }
                });
                NotificationDeliveryLogger::log(
                    $user,
                    null,
                    'mail',
                    'sent',
                    [
                        'notification_type' => 'password_expiry',
                        'recipient' => $user->email,
                        'summary' => 'Password expiry notice',
                        'request_id' => $request->headers->get('X-Request-Id'),
                    ],
                );
            } catch (\Throwable $error) {
                NotificationDeliveryLogger::log(
                    $user,
                    null,
                    'mail',
                    'failed',
                    [
                        'notification_type' => 'password_expiry',
                        'recipient' => $user->email,
                        'summary' => 'Password expiry notice',
                        'error_message' => $error->getMessage(),
                        'request_id' => $request->headers->get('X-Request-Id'),
                    ],
                );
            }
        }

        SecurityAlert::dispatch('password_expiry_notice', [
            'title' => $wasReset ? 'Password auto reset' : 'Password expiry notice',
            'user_id' => $user?->getAuthIdentifier(),
            'email' => $user?->email,
            'username' => $user?->username,
        ], $request);
    }

    private function auditPasswordReset(Request $request, mixed $user, string $action): void
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => $user ? $user::class : null,
            'auditable_id' => $user?->getAuthIdentifier(),
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
                'event' => $action,
            ],
            'created_at' => now(),
        ]);
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

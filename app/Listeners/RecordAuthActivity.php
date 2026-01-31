<?php

namespace App\Listeners;

use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use App\Support\SecurityService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordAuthActivity
{
    public function handle(object $event): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        if ($event instanceof Login) {
            $this->onLogin($event);

            return;
        }

        if ($event instanceof Failed) {
            $this->onFailed($event);

            return;
        }

        if ($event instanceof Logout) {
            $this->onLogout($event);

            return;
        }

        if ($event instanceof Lockout) {
            $this->onLockout($event);

            return;
        }

        if ($event instanceof PasswordReset) {
            $this->onPasswordReset($event);
        }
    }

    private function onLogin(Login $event): void
    {
        $request = request();
        /** @var Authenticatable&Model|null $user */
        $user = $event->user;

        if ($user instanceof Model && $user instanceof Authenticatable) {
            $now = now();
            $user->forceFill([
                'first_login_at' => $user->first_login_at ?? $now,
                'last_login_at' => $now,
                'last_login_ip' => $request->ip(),
                'last_login_user_agent' => $this->truncate((string) $request->userAgent(), 255),
                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'last_failed_login_ip' => null,
                'last_failed_login_user_agent' => null,
                'locked_at' => null,
                'blocked_until' => null,
            ])->save();
        }

        if ($user instanceof Model && $user instanceof Authenticatable && blank($user->security_stamp)) {
            $user->forceFill([
                'security_stamp' => strtoupper(Str::random(64)),
            ])->save();
        }

        if ($request->hasSession()) {
            $request->session()->put('security_stamp', $user?->security_stamp);
        }

        $roles = $this->safeRoleNames($user);
        [$permissions, $permissionsTruncated] = $this->safePermissionNames($user);

        $this->writeActivity(
            $user?->getAuthIdentifier(),
            'login_success',
            $request,
            [
                'guard' => $event->guard,
                'remember' => $event->remember,
                'roles' => $roles,
                'permissions' => $permissions,
                'permissions_truncated' => $permissionsTruncated,
            ]
        );

        SecurityAlert::dispatch('login_success', [
            'title' => 'Login success',
            'guard' => $event->guard,
            'remember' => $event->remember,
            'username' => $user?->username,
            'email' => $user?->email,
        ], $request);
    }

    private function onFailed(Failed $event): void
    {
        $request = request();
        $identity = $event->credentials['email'] ?? $event->credentials['username'] ?? null;
        /** @var Authenticatable&Model|null $user */
        $user = $event->user;
        $attempts = null;
        $lockedAt = null;
        $blockedUntil = null;
        $lockoutTriggered = false;

        if ($user instanceof Model && $user instanceof Authenticatable) {
            $maxAttempts = (int) config('security.lockout_attempts', 5);
            $lockoutMinutes = (int) config('security.lockout_minutes', 15);
            $attempts = ($user->failed_login_attempts ?? 0) + 1;
            $shouldLock = $maxAttempts > 0 && $attempts >= $maxAttempts && empty($user->locked_at);
            $blockedUntil = $shouldLock
                ? now()->addMinutes(max(1, $lockoutMinutes))
                : $user->blocked_until;
            $lockoutTriggered = $shouldLock;

            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'last_failed_login_at' => now(),
                'last_failed_login_ip' => $request->ip(),
                'last_failed_login_user_agent' => $this->truncate((string) $request->userAgent(), 255),
                'locked_at' => $shouldLock ? now() : $user->locked_at,
                'blocked_until' => $blockedUntil,
            ])->save();

            if ($shouldLock) {
                $this->writeActivity(
                    $user->getAuthIdentifier(),
                    'account_locked',
                    $request,
                    [
                        'guard' => $event->guard,
                        'identity' => $identity,
                        'blocked_until' => $blockedUntil,
                    ]
                );

                SecurityAlert::dispatch('account_locked', [
                    'title' => 'Account locked',
                    'guard' => $event->guard,
                    'identity' => $identity,
                    'blocked_until' => $blockedUntil,
                    'username' => $user?->username,
                    'email' => $user?->email,
                ], $request);
            }

            $lockedAt = $user->locked_at;
        }

        $this->writeActivity(
            $user?->getAuthIdentifier(),
            'login_failed',
            $request,
            [
                'guard' => $event->guard,
                'identity' => $identity,
                'failed_login_attempts' => $attempts,
                'locked_at' => $lockedAt,
                'blocked_until' => $blockedUntil,
                'lockout_triggered' => $lockoutTriggered,
                'rate_limited' => false,
            ]
        );

        SecurityAlert::dispatch('login_failed', [
            'title' => 'Login failed',
            'guard' => $event->guard,
            'identity' => $identity,
            'username' => $user?->username,
            'email' => $user?->email,
        ], $request);
    }

    private function onLogout(Logout $event): void
    {
        $request = request();
        $user = $event->user;
        $reason = $request->input('reason')
            ?? $request->query('reason')
            ?? $request->get('reason');

        if ($request->hasSession()) {
            $request->session()->forget('security_stamp');
        }

        $this->writeActivity(
            $user?->getAuthIdentifier(),
            'logout',
            $request,
            [
                'guard' => $event->guard,
                'reason' => $reason,
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]
        );
    }

    private function onLockout(Lockout $event): void
    {
        $request = $event->request;
        $identity = $request->input('email') ?? $request->input('username');

        $this->writeActivity(
            null,
            'lockout',
            $request,
            [
                'identity' => $identity,
                'rate_limited' => true,
            ]
        );
    }

    private function onPasswordReset(PasswordReset $event): void
    {
        $request = request();
        /** @var Authenticatable&Model|null $user */
        $user = $event->user;

        if ($user instanceof Model && $user instanceof Authenticatable) {
            $user->forceFill([
                'password_changed_at' => now(),
                'password_changed_by' => $user->getAuthIdentifier(),
                'last_password_changed_ip' => $request->ip(),
                'last_password_changed_user_agent' => $this->truncate((string) $request->userAgent(), 255),
                'must_change_password' => false,
                'security_stamp' => Str::random(64),
            ])->save();
        }

        $this->writeActivity(
            $user?->getAuthIdentifier(),
            'password_reset',
            $request,
            [
                'initiated_by' => $user?->getAuthIdentifier(),
            ]
        );

        SecurityAlert::dispatch('password_reset', [
            'title' => 'Password reset',
            'username' => $user?->username,
            'email' => $user?->email,
        ], $request);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeActivity(?int $userId, string $event, Request $request, array $context): void
    {
        $requestId = SecurityService::requestId($request);
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $userId,
            'identity' => $context['identity'] ?? null,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $this->truncate((string) $request->userAgent(), 255),
            'session_id' => $this->truncate((string) $sessionId, 100),
            'request_id' => $requestId,
            'context' => [
                'route' => (string) optional($request->route())->getName(),
                'url' => $request->fullUrl(),
                ...$context,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function safeRoleNames(?Authenticatable $user): array
    {
        if (! $user) {
            return [];
        }

        try {
            if (method_exists($user, 'getRoleNames')) {
                return $user->getRoleNames()->values()->all();
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @return array{0: array<int, string>, 1: bool}
     */
    private function safePermissionNames(?Authenticatable $user): array
    {
        if (! $user || ! method_exists($user, 'getAllPermissions')) {
            return [[], false];
        }

        try {
            $permissions = $user->getAllPermissions()
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [[], false];
        }

        $limit = 150;
        $truncated = count($permissions) > $limit;
        if ($truncated) {
            $permissions = array_slice($permissions, 0, $limit);
        }

        return [$permissions, $truncated];
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}

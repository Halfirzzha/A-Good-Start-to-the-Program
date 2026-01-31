<?php

namespace App\Http\Middleware;

use App\Support\AuditLogWriter;
use App\Support\SecurityService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
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

        $this->cleanupMissingRoles($user);

        $requiredPermission = 'access_admin_panel';
        if ($this->userCanAccess($user, $requiredPermission)) {
            $this->logGrantedOnce($request, $user, $requiredPermission);
            return $next($request);
        }

        if ($this->shouldBypass($user)) {
            $this->logBypassed($request, $user, $requiredPermission);
            return $next($request);
        }

        $requestId = SecurityService::requestId($request);
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
                'roles' => $this->safeRoleNames($user),
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
        $requestId = SecurityService::requestId($request);
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
                'roles' => $this->safeRoleNames($user),
                'permissions' => $this->safePermissionNames($user),
                'developer_bypass' => true,
            ],
            'created_at' => now(),
        ]);
    }

    private function logGrantedOnce(Request $request, mixed $user, string $requiredPermission): void
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $sessionKey = $sessionId ? 'audit.admin_access_granted' : null;

        if ($sessionKey && $request->session()->get($sessionKey)) {
            return;
        }

        $this->logGranted($request, $user, $requiredPermission);

        if ($sessionKey) {
            $request->session()->put($sessionKey, true);
        }
    }

    private function logGranted(Request $request, mixed $user, string $requiredPermission): void
    {
        $requestId = SecurityService::requestId($request);
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $user?->getAuthIdentifier(),
            'identity' => $user?->email ?? $user?->username,
            'event' => 'admin_access_granted',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'required_permission' => $requiredPermission,
                'roles' => $this->safeRoleNames($user),
                'permissions' => $this->safePermissionNames($user),
            ],
            'created_at' => now(),
        ]);
    }

    private function userCanAccess(mixed $user, string $permission): bool
    {
        try {
            return (bool) $user->can($permission);
        } catch (ModelNotFoundException) {
            $this->cleanupMissingRoles($user);
            try {
                return (bool) $user->can($permission);
            } catch (\Throwable) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function safeRoleNames(mixed $user): array
    {
        try {
            $names = $user?->getRoleNames();
            return $names ? $names->values()->all() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function safePermissionNames(mixed $user): array
    {
        try {
            if ($user && method_exists($user, 'getAllPermissions')) {
                $permissions = $user->getAllPermissions()
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->all();

                $limit = 150;
                if (count($permissions) > $limit) {
                    $permissions = array_slice($permissions, 0, $limit);
                }

                return $permissions;
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    private function cleanupMissingRoles(mixed $user): void
    {
        if (! $user || ! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $roleIds = DB::table('model_has_roles')
            ->where('model_type', $user::class)
            ->where('model_id', $user->getAuthIdentifier())
            ->pluck('role_id')
            ->unique()
            ->values()
            ->all();

        if (empty($roleIds)) {
            return;
        }

        $existingIds = Role::query()
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($roleIds, $existingIds));
        if (empty($missing)) {
            return;
        }

        DB::table('model_has_roles')
            ->where('model_type', $user::class)
            ->where('model_id', $user->getAuthIdentifier())
            ->whereIn('role_id', $missing)
            ->delete();

        AuditLogWriter::writeAudit([
            'user_id' => $user->getAuthIdentifier(),
            'action' => 'roles_auto_cleaned',
            'auditable_type' => $user::class,
            'auditable_id' => $user->getAuthIdentifier(),
            'old_values' => ['missing_role_ids' => $missing],
            'new_values' => null,
            'context' => [
                'reason' => 'role_not_found',
            ],
            'created_at' => now(),
        ]);
    }
}

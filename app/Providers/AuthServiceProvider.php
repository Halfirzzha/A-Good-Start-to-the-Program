<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\MaintenanceSetting;
use App\Models\MaintenanceToken;
use App\Models\NotificationDelivery;
use App\Models\NotificationMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserLoginActivity;
use App\Policies\AuditLogPolicy;
use App\Policies\MaintenanceSettingPolicy;
use App\Policies\MaintenanceTokenPolicy;
use App\Policies\NotificationDeliveryPolicy;
use App\Policies\NotificationMessagePolicy;
use App\Policies\RolePolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\UserLoginActivityPolicy;
use App\Policies\UserPolicy;
use App\Support\AuditLogWriter;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AuditLog::class => AuditLogPolicy::class,
        MaintenanceSetting::class => MaintenanceSettingPolicy::class,
        MaintenanceToken::class => MaintenanceTokenPolicy::class,
        NotificationDelivery::class => NotificationDeliveryPolicy::class,
        NotificationMessage::class => NotificationMessagePolicy::class,
        SystemSetting::class => SystemSettingPolicy::class,
        User::class => UserPolicy::class,
        UserLoginActivity::class => UserLoginActivityPolicy::class,
        Role::class => RolePolicy::class,
    ];

    public function boot(): void
    {
        Gate::after(function (?Authenticatable $user, string $ability, mixed $result, array $arguments = []): void {
            $allowed = $result instanceof Response ? $result->allowed() : (bool) $result;

            if ($allowed || ! $user) {
                return;
            }

            $request = request();
            $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
            $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

            [$auditableType, $auditableId, $normalizedArgs] = $this->normalizeGateArguments($arguments);

            AuditLogWriter::writeAudit([
                'user_id' => $user->getAuthIdentifier(),
                'action' => 'authorization_denied',
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => null,
                'new_values' => null,
                'ip_address' => $request?->ip(),
                'user_agent' => (string) ($request?->userAgent() ?? ''),
                'url' => $request?->fullUrl(),
                'route' => (string) optional($request?->route())->getName(),
                'method' => $request?->getMethod(),
                'status_code' => null,
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'duration_ms' => null,
                'context' => [
                    'ability' => $ability,
                    'arguments' => $normalizedArgs,
                    'path' => $request?->path(),
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array{0: string|null, 1: int|string|null, 2: array<int, mixed>}
     */
    private function normalizeGateArguments(array $arguments): array
    {
        $auditableType = null;
        $auditableId = null;
        $normalized = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof Model) {
                $auditableType ??= $argument->getMorphClass();
                $auditableId ??= $argument->getKey();
                $normalized[] = [
                    'type' => $argument->getMorphClass(),
                    'id' => $argument->getKey(),
                ];

                continue;
            }

            if (is_string($argument) && class_exists($argument)) {
                $auditableType ??= $argument;
                $normalized[] = ['type' => $argument];

                continue;
            }

            $normalized[] = is_scalar($argument) ? $argument : get_debug_type($argument);
        }

        return [$auditableType, $auditableId, $normalized];
    }
}

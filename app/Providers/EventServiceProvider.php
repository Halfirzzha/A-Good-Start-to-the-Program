<?php

namespace App\Providers;

use App\Listeners\RecordAuthActivity;
use App\Listeners\RecordNotificationFailed;
use App\Listeners\RecordNotificationSent;
use App\Support\AuditLogWriter;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        Login::class => [
            RecordAuthActivity::class,
        ],
        Failed::class => [
            RecordAuthActivity::class,
        ],
        Logout::class => [
            RecordAuthActivity::class,
        ],
        Lockout::class => [
            RecordAuthActivity::class,
        ],
        PasswordReset::class => [
            RecordAuthActivity::class,
        ],
        NotificationSent::class => [
            RecordNotificationSent::class,
        ],
        NotificationFailed::class => [
            RecordNotificationFailed::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();

        $this->registerPermissionAuditListeners();
    }

    private function registerPermissionAuditListeners(): void
    {
        $events = [
            'Spatie\\Permission\\Events\\RoleAssigned' => 'user_role_assigned',
            'Spatie\\Permission\\Events\\RoleRemoved' => 'user_role_removed',
            'Spatie\\Permission\\Events\\RoleSynced' => 'user_role_synced',
            'Spatie\\Permission\\Events\\PermissionAssigned' => 'user_permission_granted',
            'Spatie\\Permission\\Events\\PermissionRemoved' => 'user_permission_revoked',
            'Spatie\\Permission\\Events\\PermissionSynced' => 'user_permission_synced',
        ];

        foreach ($events as $eventClass => $action) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, function (object $event) use ($action): void {
                $this->handlePermissionAuditEvent($event, $action);
            });
        }
    }

    private function handlePermissionAuditEvent(object $event, string $action): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $model = $event->model ?? $event->user ?? null;
        if (! $model instanceof Model) {
            return;
        }

        $role = $event->role ?? null;
        $permission = $event->permission ?? null;
        $roles = $event->roles ?? null;
        $permissions = $event->permissions ?? null;

        $oldValues = null;
        $newValues = null;
        $context = [
            'via' => 'spatie_permission_event',
            'event' => class_basename($event),
            'target_user_id' => $model->getKey(),
        ];

        if (property_exists($model, 'email')) {
            $context['target_email'] = $model->email;
        }

        if (str_contains($action, 'role')) {
            $roleName = $role?->name ?? $role?->getAttribute('name') ?? null;
            if ($action === 'user_role_removed') {
                $oldValues = $roleName ? ['role' => $roleName] : null;
            } elseif ($action === 'user_role_assigned') {
                $newValues = $roleName ? ['role' => $roleName] : null;
            } elseif ($action === 'user_role_synced') {
                $synced = $this->normalizeNameCollection($roles);
                $newValues = ['roles' => $synced];
                $context['roles'] = $synced;
            }

            $context['role'] = $roleName;
        }

        if (str_contains($action, 'permission')) {
            $permissionName = $permission?->name ?? $permission?->getAttribute('name') ?? null;
            if ($action === 'user_permission_revoked') {
                $oldValues = $permissionName ? ['permission' => $permissionName] : null;
            } elseif ($action === 'user_permission_granted') {
                $newValues = $permissionName ? ['permission' => $permissionName] : null;
            } elseif ($action === 'user_permission_synced') {
                $synced = $this->normalizeNameCollection($permissions);
                $newValues = ['permissions' => $synced];
                $context['permissions'] = $synced;
            }

            $context['permission'] = $permissionName;
        }

        if (! $this->shouldLogPermissionEvent($action, $model, $context)) {
            return;
        }

        AuditLogWriter::writeAudit([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeNameCollection(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(function ($item): ?string {
                if (is_string($item)) {
                    return $item;
                }
                if ($item instanceof Model) {
                    return (string) ($item->getAttribute('name') ?? '');
                }

                return null;
            })->filter()->values()->all();
        }

        if (is_array($value)) {
            return collect($value)->map(function ($item): ?string {
                if (is_string($item)) {
                    return $item;
                }
                if ($item instanceof Model) {
                    return (string) ($item->getAttribute('name') ?? '');
                }

                return null;
            })->filter()->values()->all();
        }

        return [];
    }

    private function shouldLogPermissionEvent(string $action, Model $model, array $context): bool
    {
        $request = request();
        if (! $request) {
            return true;
        }

        $parts = [
            $action,
            $model->getMorphClass(),
            $model->getKey(),
            (string) ($context['role'] ?? ''),
            (string) ($context['permission'] ?? ''),
        ];

        if (isset($context['roles']) && is_array($context['roles'])) {
            $parts[] = implode(',', $context['roles']);
        }

        if (isset($context['permissions']) && is_array($context['permissions'])) {
            $parts[] = implode(',', $context['permissions']);
        }

        $key = implode('|', $parts);
        $bag = $request->attributes->get('audit_permission_logged', []);

        if (is_array($bag) && array_key_exists($key, $bag)) {
            return false;
        }

        if (! is_array($bag)) {
            $bag = [];
        }

        $bag[$key] = true;
        $request->attributes->set('audit_permission_logged', $bag);

        return true;
    }
}

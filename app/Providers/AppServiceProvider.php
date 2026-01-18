<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\MaintenanceSetting;
use App\Models\SystemSetting;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\MaintenanceService;
use App\Support\SecurityAlert;
use App\Support\SystemSettings;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Livewire\Livewire;
use Masbug\Flysystem\GoogleDriveAdapter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    private const AVATAR_DELETE_QUEUE = 'user_avatar_delete_queue';

    private const AVATAR_DELETE_LOCK = 'user_avatar_delete_lock';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        config([
            'filament-shield.super_admin.enabled' => false,
            'filament-shield.panel_user.enabled' => false,
            'filament-shield.permissions.separator' => '_',
            'filament-shield.permissions.case' => 'snake',
            'filament-shield.permissions.generate' => true,
            'filament-shield.policies.merge' => false,
            'filament-shield.policies.generate' => false,
            'filament-shield.policies.methods' => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
                'restoreAny', 'forceDelete', 'forceDeleteAny',
            ],
            'filament-shield.policies.single_parameter_methods' => [
                'viewAny',
                'create',
                'deleteAny',
                'forceDeleteAny',
                'restoreAny',
            ],
            'filament-shield.resources.manage' => [
                \BezhanSalleh\FilamentShield\Resources\Roles\RoleResource::class => [
                    'viewAny',
                    'view',
                    'create',
                    'update',
                    'delete',
                ],
                \App\Filament\Resources\NotificationMessageResource::class => [
                    'viewAny',
                    'view',
                    'create',
                    'update',
                    'delete',
                ],
                \App\Filament\Resources\NotificationDeliveryResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\UserNotificationResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\UnifiedHistoryResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\AuditLogResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\SystemSettingResource::class => [
                    'viewAny',
                    'view',
                    'update',
                ],
                \App\Filament\Resources\MaintenanceSettingResource::class => [
                    'viewAny',
                    'view',
                    'update',
                ],
            ],
            'filament-shield.shield_resource.tabs.custom_permissions' => true,
            'filament-shield.pages.exclude' => [
                \BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles::class,
                \BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole::class,
                \BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole::class,
                \BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole::class,
            ],
            'filament-shield.widgets.exclude' => [],
            'filament-shield.custom_permissions' => [
                'access_admin_panel',
                'assign_roles',
                'execute_user_unlock',
                'execute_user_activate',
                'execute_user_force_password_reset',
                'execute_user_revoke_sessions',
                'manage_user_avatar',
                'manage_user_identity',
                'manage_user_security',
                'manage_user_access_status',
                'view_user_system_info',
                'execute_maintenance_bypass_token',
                'execute_notification_send',
                'execute_unified_history_create',
                'manage_system_setting_secrets',
                'manage_system_setting_project_url',
                'manage_system_settings_project',
                'manage_system_settings_branding',
                'manage_system_settings_storage',
                'manage_system_settings_communication',
                'manage_maintenance_schedule',
                'manage_maintenance_message',
                'manage_maintenance_access',
            ],
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->enforceHttpsScheme();
        $this->registerLivewireComponentAliases();
        $this->registerGoogleDriveFilesystem();
        $this->registerSlowQueryLogger();
        $this->ensureSystemSettingsRecord();
        $this->ensureMaintenanceSettingsRecord();
        $this->ensureDeveloperBootstrapPermissions();
        $this->ensureUnifiedHistoryBootstrap();
        $this->ensureUnifiedHistoryHardeningEntry();
        $this->ensureMaintenanceRealtimeEntry();
        $this->ensureHealthDashboardEntry();
        $this->cleanupUserAvatarQueue();

        RateLimiter::for('admin-panel', function (Request $request): Limit {
            $userId = null;
            try {
                $userId = $request->user()?->getAuthIdentifier();
            } catch (\Throwable) {
                $userId = null;
            }

            $key = $userId ?: $request->ip();

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('maintenance-bypass', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('health-check', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('maintenance-status', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('maintenance-stream', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('invitation', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request): Limit {
            $identity = (string) ($request->input('username') ?? $request->ip());

            return Limit::perMinute(10)->by($identity);
        });

        RateLimiter::for('auth-otp', function (Request $request): Limit {
            $identity = (string) ($request->input('username') ?? $request->ip());

            return Limit::perMinute(6)->by($identity);
        });

        Gate::before(function ($user, string $ability): ?bool {
            if (method_exists($user, 'isDeveloper') && $user->isDeveloper()) {
                return true;
            }

            return null;
        });

        Gate::after(function ($user, string $ability, mixed $result, array $arguments): void {
            if ($result !== false || app()->runningInConsole()) {
                return;
            }

            $request = request();
            if (! $request || ! $this->shouldLogAuthorizationDenied($request)) {
                return;
            }

            $auditableType = null;
            $auditableId = null;
            $firstArg = $arguments[0] ?? null;
            if ($firstArg instanceof Model) {
                $auditableType = $firstArg->getMorphClass();
                $auditableId = $firstArg->getKey();
            }

            AuditLogWriter::writeAudit([
                'user_id' => AuthHelper::id(),
                'action' => 'authorization_denied',
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => null,
                'new_values' => null,
                'context' => [
                    'ability' => $ability,
                    'route' => $request->route()?->getName(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'arguments' => $this->summarizeGateArguments($arguments),
                ],
                'created_at' => now(),
            ]);
        });

        Role::created(function (Role $role): void {
            if (! $this->safeHasTable('permissions')) {
                return;
            }

            $guardName = $role->guard_name ?: config('auth.defaults.guard', 'web');
            $permission = Permission::firstOrCreate([
                'name' => 'assign_roles',
                'guard_name' => $guardName,
            ]);

            if ($role->hasPermissionTo($permission)) {
                return;
            }

            if ($role->name === (string) config('security.developer_role', 'developer')) {
                $role->givePermissionTo($permission);
                $permissions = Permission::query()
                    ->where('guard_name', $guardName)
                    ->get();
                if ($permissions->isNotEmpty()) {
                    $role->syncPermissions($permissions);
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });

        Role::updated(function (Role $role): void {
            if (app()->runningInConsole() || ! $this->safeHasTable('roles')) {
                return;
            }

            $changes = Arr::only($role->getChanges(), ['name', 'guard_name']);
            $original = Arr::only($role->getOriginal(), array_keys($changes));
            $this->writeRolePermissionAudit('role_updated', $role, $original, $changes);

            SecurityAlert::dispatch('role_updated', [
                'title' => 'Role updated',
                'role' => $role->name,
            ]);
        });

        Role::deleted(function (Role $role): void {
            if (app()->runningInConsole() || ! $this->safeHasTable('roles')) {
                return;
            }

            $this->writeRolePermissionAudit('role_deleted', $role, Arr::only($role->getOriginal(), ['name', 'guard_name']), null);

            SecurityAlert::dispatch('role_deleted', [
                'title' => 'Role deleted',
                'role' => $role->name,
            ]);
        });

        Permission::created(function (Permission $permission): void {
            $developerRoleName = (string) config('security.developer_role', 'developer');
            $guardName = $permission->guard_name ?: config('auth.defaults.guard', 'web');

            $developerRole = Role::where('name', $developerRoleName)
                ->where('guard_name', $guardName)
                ->first();

            if (! $developerRole || $developerRole->hasPermissionTo($permission)) {
                return;
            }

            $developerRole->givePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        Permission::updated(function (Permission $permission): void {
            if (app()->runningInConsole() || ! $this->safeHasTable('permissions')) {
                return;
            }

            $changes = Arr::only($permission->getChanges(), ['name', 'guard_name']);
            $original = Arr::only($permission->getOriginal(), array_keys($changes));
            $this->writeRolePermissionAudit('permission_updated', $permission, $original, $changes);

            SecurityAlert::dispatch('permission_updated', [
                'title' => 'Permission updated',
                'permission' => $permission->name,
            ]);
        });

        Permission::deleted(function (Permission $permission): void {
            if (app()->runningInConsole() || ! $this->safeHasTable('permissions')) {
                return;
            }

            $this->writeRolePermissionAudit('permission_deleted', $permission, Arr::only($permission->getOriginal(), ['name', 'guard_name']), null);

            SecurityAlert::dispatch('permission_deleted', [
                'title' => 'Permission deleted',
                'permission' => $permission->name,
            ]);
        });

        if (app()->runningInConsole()) {
            return;
        }

        Filament::serving(function (): void {
            $this->ensureShieldPermissionsAvailable();
            FilamentShield::prohibitDestructiveCommands();
        });
    }

    private function enforceHttpsScheme(): void
    {
        $appUrl = (string) config('app.url', '');
        if (! app()->environment('production') || ! str_starts_with($appUrl, 'https://')) {
            return;
        }

        URL::forceScheme('https');
    }

    private function registerSlowQueryLogger(): void
    {
        $threshold = (int) config('observability.slow_query_ms', 0);
        if ($threshold <= 0) {
            return;
        }

        DB::listen(function ($query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            $bindings = $query->bindings ?? [];
            $payload = [
                'connection' => $query->connectionName,
                'time_ms' => $query->time,
                'sql' => $query->sql,
                'bindings_count' => is_array($bindings) ? count($bindings) : 0,
            ];

            if (config('observability.log_query_bindings', false)) {
                $payload['bindings'] = $bindings;
            }

            try {
                Log::channel('performance')->warning('slow_query', $payload);
            } catch (\Throwable) {
                Log::warning('slow_query', $payload);
            }
        });
    }

    private function ensureDeveloperBootstrapPermissions(): void
    {
        if (! $this->safeHasTable('roles') || ! $this->safeHasTable('permissions')) {
            return;
        }

        $guardName = config('auth.defaults.guard', 'web');
        $developerRoleName = (string) config('security.developer_role', 'developer');

        $developerRole = Role::firstOrCreate([
            'name' => $developerRoleName,
            'guard_name' => $guardName,
        ]);

        $customPermissions = config('filament-shield.custom_permissions', []);
        if (! is_array($customPermissions)) {
            $customPermissions = [];
        }

        $customPermissions[] = 'access_admin_panel';
        $customPermissions = array_values(array_unique(array_filter($customPermissions, 'is_string')));

        $created = [];
        foreach ($customPermissions as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);

            if ($permission->wasRecentlyCreated) {
                $created[] = $permissionName;
            }
        }

        $allPermissions = Permission::query()
            ->where('guard_name', $guardName)
            ->pluck('name')
            ->all();

        $missingAssignments = [];
        foreach ($allPermissions as $permissionName) {
            if (! $developerRole->hasPermissionTo($permissionName)) {
                $missingAssignments[] = $permissionName;
            }
        }

        if (! empty($missingAssignments)) {
            $developerRole->givePermissionTo($missingAssignments);
        }

        if (empty($created) && empty($missingAssignments)) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'permissions_bootstrap',
            'auditable_type' => Role::class,
            'auditable_id' => $developerRole->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'user_agent' => null,
            'url' => null,
            'route' => null,
            'method' => null,
            'status_code' => null,
            'request_id' => null,
            'session_id' => null,
            'duration_ms' => null,
            'context' => [
                'role' => $developerRole->name,
                'guard' => $guardName,
                'permissions_created' => $created,
                'permissions_assigned' => $missingAssignments,
            ],
            'created_at' => now(),
        ]);
    }

    private function ensureShieldPermissionsAvailable(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! $this->safeHasTable('permissions')) {
            return;
        }

        $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
        if ($panel) {
            Filament::setCurrentPanel($panel);
        }

        $resourcePermissions = FilamentShield::getAllResourcePermissionsWithLabels();
        $pagePermissions = collect(FilamentShield::getPages() ?? [])
            ->flatMap(fn (array $page): array => array_keys($page['permissions'] ?? []))
            ->all();
        $widgetPermissions = collect(FilamentShield::getWidgets() ?? [])
            ->flatMap(fn (array $widget): array => array_keys($widget['permissions'] ?? []))
            ->all();
        $customPermissions = array_keys(FilamentShield::getCustomPermissions() ?? []);

        $permissions = collect($resourcePermissions)
            ->keys()
            ->merge($pagePermissions)
            ->merge($widgetPermissions)
            ->merge($customPermissions)
            ->unique()
            ->values()
            ->all();

        if (empty($permissions)) {
            return;
        }

        $guardName = config('auth.defaults.guard', 'web');
        $existing = Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $permissions)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($permissions, $existing));
        if (empty($missing)) {
            return;
        }

        foreach ($missing as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'permissions_bootstrap',
            'auditable_type' => Permission::class,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => ['created' => $missing],
            'ip_address' => null,
            'user_agent' => null,
            'url' => null,
            'route' => null,
            'method' => null,
            'status_code' => null,
            'request_id' => null,
            'session_id' => null,
            'created_at' => now(),
        ]);
    }

    private function shouldLogAuthorizationDenied(Request $request): bool
    {
        if (! $request->user()) {
            return false;
        }

        if (str_starts_with((string) $request->route()?->getName(), 'filament.admin.')) {
            return true;
        }

        return $request->is('admin/*');
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array<int, string|array<string, mixed>>
     */
    private function summarizeGateArguments(array $arguments): array
    {
        $summary = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof Model) {
                $summary[] = [
                    'type' => $argument->getMorphClass(),
                    'id' => $argument->getKey(),
                ];

                continue;
            }

            if (is_scalar($argument) || $argument === null) {
                $summary[] = $argument;

                continue;
            }

            if (is_array($argument)) {
                $summary[] = [
                    'type' => 'array',
                    'keys' => array_keys($argument),
                ];

                continue;
            }

            $summary[] = [
                'type' => get_debug_type($argument),
            ];
        }

        return $summary;
    }

    private function ensureSystemSettingsRecord(): void
    {
        if (! $this->safeHasTable('system_settings')) {
            return;
        }

        if (SystemSetting::query()->exists()) {
            return;
        }

        $defaults = SystemSettings::defaults();
        $data = is_array($defaults['data'] ?? null) ? $defaults['data'] : [];
        $secrets = is_array($defaults['secrets'] ?? null) ? $defaults['secrets'] : [];

        SystemSetting::withoutEvents(function () use ($data, $secrets): void {
            SystemSetting::query()->create([
                'project_name' => (string) Arr::get($data, 'project.name', config('app.name', 'System')),
                'project_description' => Arr::get($data, 'project.description'),
                'project_url' => Arr::get($data, 'project.url', config('app.url')),
                'branding_logo_disk' => Arr::get($data, 'branding.logo.disk'),
                'branding_logo_path' => Arr::get($data, 'branding.logo.path'),
                'branding_logo_fallback_disk' => Arr::get($data, 'branding.logo.fallback_disk'),
                'branding_logo_fallback_path' => Arr::get($data, 'branding.logo.fallback_path'),
                'branding_logo_status' => Arr::get($data, 'branding.logo.status', 'unset'),
                'branding_logo_updated_at' => Arr::get($data, 'branding.logo.updated_at'),
                'branding_cover_disk' => Arr::get($data, 'branding.cover.disk'),
                'branding_cover_path' => Arr::get($data, 'branding.cover.path'),
                'branding_cover_fallback_disk' => Arr::get($data, 'branding.cover.fallback_disk'),
                'branding_cover_fallback_path' => Arr::get($data, 'branding.cover.fallback_path'),
                'branding_cover_status' => Arr::get($data, 'branding.cover.status', 'unset'),
                'branding_cover_updated_at' => Arr::get($data, 'branding.cover.updated_at'),
                'branding_favicon_disk' => Arr::get($data, 'branding.favicon.disk'),
                'branding_favicon_path' => Arr::get($data, 'branding.favicon.path'),
                'branding_favicon_fallback_disk' => Arr::get($data, 'branding.favicon.fallback_disk'),
                'branding_favicon_fallback_path' => Arr::get($data, 'branding.favicon.fallback_path'),
                'branding_favicon_status' => Arr::get($data, 'branding.favicon.status', 'unset'),
                'branding_favicon_updated_at' => Arr::get($data, 'branding.favicon.updated_at'),
                'storage_primary_disk' => Arr::get($data, 'storage.primary_disk', 'google'),
                'storage_fallback_disk' => Arr::get($data, 'storage.fallback_disk', 'public'),
                'storage_drive_root' => Arr::get($data, 'storage.drive_root'),
                'storage_drive_folder_branding' => Arr::get($data, 'storage.drive_folder_branding'),
                'storage_drive_folder_favicon' => Arr::get($data, 'storage.drive_folder_favicon'),
                'email_enabled' => (bool) Arr::get($data, 'notifications.email.enabled', true),
                'email_provider' => Arr::get($data, 'notifications.email.provider'),
                'email_from_name' => Arr::get($data, 'notifications.email.from_name'),
                'email_from_address' => Arr::get($data, 'notifications.email.from_address'),
                'email_auth_from_name' => Arr::get($data, 'notifications.email.auth_from_name'),
                'email_auth_from_address' => Arr::get($data, 'notifications.email.auth_from_address'),
                'email_recipients' => Arr::get($data, 'notifications.email.recipients', []),
                'smtp_mailer' => Arr::get($data, 'notifications.email.mailer', 'smtp'),
                'smtp_host' => Arr::get($data, 'notifications.email.smtp_host'),
                'smtp_port' => Arr::get($data, 'notifications.email.smtp_port', 587),
                'smtp_encryption' => Arr::get($data, 'notifications.email.smtp_encryption'),
                'smtp_username' => Arr::get($data, 'notifications.email.smtp_username'),
                'smtp_password' => Arr::get($secrets, 'notifications.email.smtp_password'),
                'telegram_enabled' => (bool) Arr::get($data, 'notifications.telegram.enabled', false),
                'telegram_chat_id' => Arr::get($data, 'notifications.telegram.chat_id'),
                'telegram_bot_token' => Arr::get($secrets, 'telegram.bot_token'),
                'google_drive_service_account_json' => Arr::get($secrets, 'google_drive.service_account_json'),
                'google_drive_client_id' => Arr::get($secrets, 'google_drive.client_id'),
                'google_drive_client_secret' => Arr::get($secrets, 'google_drive.client_secret'),
                'google_drive_refresh_token' => Arr::get($secrets, 'google_drive.refresh_token'),
            ]);
        });
    }

    private function ensureMaintenanceSettingsRecord(): void
    {
        if (! $this->safeHasTable('maintenance_settings')) {
            return;
        }

        if (MaintenanceSetting::query()->exists()) {
            return;
        }

        $defaults = MaintenanceService::getSettings();

        MaintenanceSetting::withoutEvents(function () use ($defaults): void {
            MaintenanceSetting::query()->create([
                'enabled' => (bool) ($defaults['enabled'] ?? false),
                'mode' => (string) ($defaults['mode'] ?? 'global'),
                'title' => $defaults['title'] ?? null,
                'summary' => $defaults['summary'] ?? null,
                'note_html' => $defaults['note_html'] ?? null,
                'start_at' => $defaults['start_at'] ?? null,
                'end_at' => $defaults['end_at'] ?? null,
                'retry_after' => $defaults['retry_after'] ?? null,
                'allow_roles' => $defaults['allow_roles'] ?? [],
                'allow_ips' => $defaults['allow_ips'] ?? [],
                'allow_paths' => $defaults['allow_paths'] ?? [],
                'deny_paths' => $defaults['deny_paths'] ?? [],
                'allow_routes' => $defaults['allow_routes'] ?? [],
                'deny_routes' => $defaults['deny_routes'] ?? [],
                'allow_api' => (bool) ($defaults['allow_api'] ?? false),
                'allow_developer_bypass' => (bool) ($defaults['allow_developer_bypass'] ?? false),
            ]);
        });
    }

    private function ensureUnifiedHistoryBootstrap(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! $this->safeHasTable('audit_logs')) {
            return;
        }

        if (AuditLog::query()->where('action', 'unified_history_bootstrap')->exists()) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'unified_history_bootstrap',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'category' => 'project',
                'scope' => ['architecture', 'permission'],
                'title' => 'Unified History module initialized',
                'summary' => 'Created a dedicated Filament module to consolidate project, security, deep scan, and hardening history.',
                'details' => 'New files: app/Filament/Resources/UnifiedHistoryResource.php; app/Filament/Resources/UnifiedHistoryResource/Pages/ListUnifiedHistories.php. Reason: separate permissions and provide structured, auditable history entries without broad audit log access.',
                'entry_type' => 'system',
                'tags' => ['auditability', 'governance', 'traceability'],
            ],
            'created_at' => now(),
        ]);
    }

    private function ensureUnifiedHistoryHardeningEntry(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! $this->safeHasTable('audit_logs')) {
            return;
        }

        $entryKey = 'hardening-maintenance-validation-v2';
        $exists = AuditLog::query()
            ->where('action', 'unified_history_entry')
            ->where('context->entry_key', $entryKey)
            ->exists();

        if ($exists) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'unified_history_entry',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'entry_key' => $entryKey,
                'category' => 'hardening',
                'scope' => ['code', 'configuration', 'permission'],
                'title' => 'Maintenance and settings hardening pass',
                'summary' => 'Tightened maintenance bypass controls, validated settings inputs, and alerted on role assignments.',
                'details' => 'Added a maintenance status indicator, gated developer maintenance bypass behind an explicit toggle, enforced IP/CIDR, path, and route pattern validation, and logged role assignment changes with security alerts.',
                'findings' => 'Maintenance bypass could silently allow developer access when SECURITY_DEVELOPER_BYPASS_VALIDATIONS was enabled, and settings inputs accepted unvalidated patterns.',
                'mitigations' => 'Introduced allow_developer_bypass, added strict validation for maintenance and notification settings, and added role assignment audit/alerts.',
                'configuration_changes' => 'Added data.maintenance.allow_developer_bypass default false.',
                'tags' => ['maintenance', 'validation', 'security-alerts', 'audit'],
                'entry_type' => 'system',
            ],
            'created_at' => now(),
        ]);
    }

    private function ensureMaintenanceRealtimeEntry(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! $this->safeHasTable('audit_logs')) {
            return;
        }

        $entryKey = 'maintenance-realtime-status';
        if (AuditLog::query()->where('action', 'unified_history_entry')->where('context->entry_key', $entryKey)->exists()) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'unified_history_entry',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'entry_key' => $entryKey,
                'category' => 'project',
                'scope' => ['architecture', 'configuration'],
                'title' => 'Real-time maintenance observability',
                'summary' => 'Added live status endpoint and maintenance page polling so operators see real-time maintenance windows, retries, and status labels.',
                'details' => 'New route /maintenance/status, JSON payload includes status_label, retry_after, and server time; maintenance page script polls this endpoint every 10 seconds and updates timers/status indicators; metadata added for SEO/accessibility; introduced SystemHealth helper for monitoring.',
                'findings' => 'Old maintenance page was static and could become stale while maintenance window changed.',
                'mitigations' => 'Implemented heartbeat endpoint plus frontend polling, status description, and retry visibility for improved reliability.',
                'configuration_changes' => ['route', 'view', 'frontend-script', 'app/Support/SystemHealth.php'],
                'tags' => ['maintenance', 'real-time', 'seo', 'accessibility'],
                'entry_type' => 'system',
            ],
            'created_at' => now(),
        ]);
    }

    private function ensureHealthDashboardEntry(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! $this->safeHasTable('audit_logs')) {
            return;
        }

        $entryKey = 'health-dashboard';
        if (AuditLog::query()->where('action', 'unified_history_entry')->where('context->entry_key', $entryKey)->exists()) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => 'unified_history_entry',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'entry_key' => $entryKey,
                'category' => 'architecture',
                'scope' => ['monitoring', 'observability'],
                'title' => 'Health dashboard',
                'summary' => 'Dashboard baru menampilkan health checks & maintenance snapshot secara real-time.',
                'details' => 'Ditambahkan route /health/dashboard plus assets JS untuk polling health endpoint, ideal untuk operator dan observability.',
                'tags' => ['monitoring', 'enterprise', 'observability'],
                'entry_type' => 'system',
            ],
            'created_at' => now(),
        ]);
    }

    private function registerLivewireComponentAliases(): void
    {
        Livewire::component(
            'filament.livewire.database-notifications',
            \App\Filament\Livewire\DatabaseNotifications::class
        );
    }

    private function registerGoogleDriveFilesystem(): void
    {
        Storage::extend('google', function ($app, $config): FilesystemAdapter {
            if (! class_exists(GoogleClient::class) || ! class_exists(Drive::class)) {
                Log::warning('storage.google_drive.missing_dependency');

                return $this->fallbackGoogleFilesystem();
            }

            try {
                return $this->buildGoogleFilesystem((array) $config);
            } catch (\Throwable $error) {
                Log::warning('storage.google_drive.unavailable', [
                    'error' => $error->getMessage(),
                ]);

                return $this->fallbackGoogleFilesystem();
            }
        });
    }

    private function cleanupUserAvatarQueue(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! Cache::add(self::AVATAR_DELETE_LOCK, true, 60)) {
            return;
        }

        $queue = Cache::get(self::AVATAR_DELETE_QUEUE, []);
        if (! is_array($queue) || $queue === []) {
            return;
        }

        $now = now();
        $remaining = [];

        foreach ($queue as $item) {
            if (! is_array($item)) {
                continue;
            }

            $disk = $item['disk'] ?? null;
            $path = $item['path'] ?? null;
            $deleteAt = $item['delete_at'] ?? null;

            if (! is_string($disk) || ! is_string($path) || ! is_string($deleteAt)) {
                continue;
            }

            try {
                $due = Carbon::parse($deleteAt);
            } catch (\Throwable) {
                $remaining[] = $item;

                continue;
            }

            if ($now->gte($due)) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable) {
                    $remaining[] = $item;
                }

                continue;
            }

            $remaining[] = $item;
        }

        Cache::put(self::AVATAR_DELETE_QUEUE, $remaining, now()->addDays(8));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildGoogleFilesystem(array $config): FilesystemAdapter
    {
        $config = $this->mergeGoogleDriveConfig($config);

        $client = $this->makeGoogleClient($config);
        $service = new Drive($client);

        $options = [];
        if (! empty($config['team_drive_id'])) {
            $options['teamDriveId'] = $config['team_drive_id'];
        }
        if (! empty($config['shared_folder_id'])) {
            $options['sharedFolderId'] = $config['shared_folder_id'];
        }

        $adapter = new GoogleDriveAdapter($service, $config['root'] ?? null, $options);
        $filesystem = new Filesystem($adapter);

        return new FilesystemAdapter($filesystem, $adapter, $config);
    }

    private function fallbackGoogleFilesystem(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 'local',
            'root' => storage_path('app/google-fallback'),
            'throw' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function mergeGoogleDriveConfig(array $config): array
    {
        $storageRoot = SystemSettings::getValue('storage.drive_root');
        if (is_string($storageRoot) && $storageRoot !== '') {
            $config['root'] = $storageRoot;
        }

        $secrets = SystemSettings::getSecret('google_drive', []);
        if (is_array($secrets)) {
            foreach (['service_account_json', 'client_id', 'client_secret', 'refresh_token'] as $key) {
                if (! empty($secrets[$key])) {
                    $config[$key] = $secrets[$key];
                }
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeGoogleClient(array $config): GoogleClient
    {
        $client = new GoogleClient;
        $client->setScopes([Drive::DRIVE]);
        $client->setAccessType('offline');

        $serviceAccountJson = $config['service_account_json'] ?? null;
        if (is_string($serviceAccountJson) && $serviceAccountJson !== '') {
            if (is_file($serviceAccountJson)) {
                $client->setAuthConfig($serviceAccountJson);

                return $client;
            }

            $decoded = json_decode($serviceAccountJson, true);
            if (is_array($decoded)) {
                $client->setAuthConfig($decoded);

                return $client;
            }
        }

        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;
        $refreshToken = $config['refresh_token'] ?? null;

        if (! $clientId || ! $clientSecret || ! $refreshToken) {
            throw new \RuntimeException('Google Drive credentials not configured.');
        }

        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->refreshToken($refreshToken);

        return $client;
    }

    /**
     * @param  array<string, mixed> | null  $oldValues
     * @param  array<string, mixed> | null  $newValues
     */
    private function writeRolePermissionAudit(string $action, mixed $model, ?array $oldValues, ?array $newValues): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => method_exists($model, 'getMorphClass') ? $model->getMorphClass() : null,
            'auditable_id' => method_exists($model, 'getKey') ? $model->getKey() : null,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'source' => app()->runningInConsole() ? 'console' : 'http',
            ],
            'created_at' => now(),
        ]);
    }

    private function safeHasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}

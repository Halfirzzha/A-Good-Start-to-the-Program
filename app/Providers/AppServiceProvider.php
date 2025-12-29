<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Support\SecurityAlert;
use App\Support\SystemSettings;
use App\Support\AuditLogWriter;
use App\Models\SystemSetting;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\RateLimiter;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
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
                \App\Filament\Resources\UnifiedHistoryResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\AuditLogResource::class => [
                    'viewAny',
                    'view',
                ],
                \App\Filament\Resources\UserLoginActivityResource::class => [
                    'viewAny',
                    'view',
                ],
            ],
            'filament-shield.shield_resource.tabs.custom_permissions' => true,
            'filament-shield.pages.exclude' => [],
            'filament-shield.widgets.exclude' => [],
            'filament-shield.custom_permissions' => [
                'access_admin_panel',
                'assign_roles',
                'execute_user_unlock',
                'execute_user_activate',
                'execute_user_force_password_reset',
                'execute_user_revoke_sessions',
                'execute_maintenance_bypass_token',
                'execute_unified_history_create',
            ],
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGoogleDriveFilesystem();
        $this->ensureSystemSettingsRecord();
        $this->ensureDeveloperBootstrapPermissions();
        $this->ensureUnifiedHistoryBootstrap();
        $this->ensureUnifiedHistoryHardeningEntry();
        $this->ensureMaintenanceRealtimeEntry();
        $this->ensureHealthDashboardEntry();

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

        Role::created(function (Role $role): void {
            if (! Schema::hasTable('permissions')) {
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
            if (app()->runningInConsole() || ! Schema::hasTable('roles')) {
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
            if (app()->runningInConsole() || ! Schema::hasTable('roles')) {
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
            if (app()->runningInConsole() || ! Schema::hasTable('permissions')) {
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
            if (app()->runningInConsole() || ! Schema::hasTable('permissions')) {
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

    private function ensureDeveloperBootstrapPermissions(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
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

        if (! Schema::hasTable('permissions')) {
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

    private function ensureSystemSettingsRecord(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        if (SystemSetting::query()->exists()) {
            return;
        }

        $defaults = SystemSettings::defaults();

        SystemSetting::withoutEvents(function () use ($defaults): void {
            SystemSetting::query()->create([
                'data' => $defaults['data'] ?? [],
                'secrets' => $defaults['secrets'] ?? [],
            ]);
        });
    }

    private function ensureUnifiedHistoryBootstrap(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (! Schema::hasTable('audit_logs')) {
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

        if (! Schema::hasTable('audit_logs')) {
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

        if (! Schema::hasTable('audit_logs')) {
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

        if (! Schema::hasTable('audit_logs')) {
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

    private function registerGoogleDriveFilesystem(): void
    {
        Storage::extend('google', function ($app, $config): FilesystemAdapter {
            return $this->buildGoogleFilesystem((array) $config);
        });
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
        $client = new GoogleClient();
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

}

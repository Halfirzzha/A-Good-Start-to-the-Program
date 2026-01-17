<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemSettings;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Custom permissions for fine-grained access control.
     */
    private array $customPermissions = [
        // Admin Panel Access
        'access_admin_panel',

        // User Management - Sections
        'manage_user_avatar',
        'manage_user_identity',
        'manage_user_security',
        'manage_user_access_status',
        'view_user_system_info',
        'assign_roles',

        // User Management - Actions
        'execute_user_unlock',
        'execute_user_activate',
        'execute_user_force_password_reset',
        'execute_user_revoke_sessions',

        // System Settings - Sections
        'view_system_setting_branding',
        'manage_system_setting_branding',
        'view_system_setting_storage',
        'manage_system_setting_storage',
        'view_system_setting_communication',
        'manage_system_setting_communication',
        'view_system_setting_ai',
        'manage_system_setting_ai',
        'edit_system_setting_secrets',
        'edit_system_setting_project_url',
        'test_system_setting_smtp',
        'test_system_setting_ai',

        // Security Management
        'manage_security',
        'view_security_logs',
        'manage_ip_blocklist',
        'view_security_dashboard',

        // Notification Actions
        'execute_notification_send',
        'retry_notification_delivery',
        'delete_sent_notification_message',
        'send_notification_message',

        // User Notifications (Inbox)
        'view_any_user_notification',
        'view_user_notification',
        'update_user_notification',
        'delete_user_notification',
        'delete_any_user_notification',

        // Maintenance Actions
        'manage_maintenance_tokens',
        'toggle_maintenance',

        // Role Management
        'view_any_role',
        'view_role',
        'create_role',
        'update_role',
        'delete_role',
        'delete_any_role',
        'restore_role',
        'restore_any_role',
        'force_delete_role',
        'force_delete_any_role',
    ];

    /**
     * Role-based permission mappings.
     * Developer gets ALL permissions automatically.
     * SuperAdmin gets most permissions except developer-only ones.
     * Other roles get limited permissions.
     *
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'super_admin' => [
            // Full access to users
            'view_any_user', 'view_user', 'create_user', 'update_user', 'delete_user',
            'delete_any_user', 'restore_user', 'restore_any_user', 'force_delete_user',
            'manage_user_avatar', 'manage_user_identity', 'manage_user_security',
            'manage_user_access_status', 'view_user_system_info', 'assign_roles',
            'execute_user_unlock', 'execute_user_activate', 'execute_user_force_password_reset',
            'execute_user_revoke_sessions',

            // Full access to audit logs
            'view_any_audit_log', 'view_audit_log',

            // Full access to login activities
            'view_any_user_login_activity', 'view_user_login_activity',

            // Full access to system settings
            'view_any_system_setting', 'view_system_setting', 'update_system_setting',
            'view_system_setting_branding', 'manage_system_setting_branding',
            'view_system_setting_storage', 'manage_system_setting_storage',
            'view_system_setting_communication', 'manage_system_setting_communication',
            'view_system_setting_ai', 'manage_system_setting_ai',
            'edit_system_setting_secrets', 'edit_system_setting_project_url',

            // Security Management
            'manage_security', 'view_security_logs', 'manage_ip_blocklist', 'view_security_dashboard',

            // Full access to notifications
            'view_any_notification_message', 'view_notification_message',
            'create_notification_message', 'update_notification_message',
            'delete_notification_message', 'delete_any_notification_message',
            'execute_notification_send', 'delete_sent_notification_message',
            'view_any_notification_delivery', 'view_notification_delivery',
            'retry_notification_delivery',

            // Full access to maintenance
            'view_any_maintenance_setting', 'view_maintenance_setting',
            'update_maintenance_setting', 'manage_maintenance_tokens', 'toggle_maintenance',
            'view_any_maintenance_token', 'view_maintenance_token',
            'create_maintenance_token', 'update_maintenance_token',
            'delete_maintenance_token', 'delete_any_maintenance_token',

            // Admin panel access
            'access_admin_panel',
        ],
        'admin' => [
            // User management (limited)
            'view_any_user', 'view_user', 'create_user', 'update_user',
            'manage_user_avatar', 'manage_user_identity', 'manage_user_security',
            'manage_user_access_status', 'view_user_system_info',
            'execute_user_unlock', 'execute_user_activate',

            // Audit logs (view only)
            'view_any_audit_log', 'view_audit_log',

            // Login activities (view only)
            'view_any_user_login_activity', 'view_user_login_activity',

            // System settings (limited)
            'view_any_system_setting', 'view_system_setting',
            'view_system_setting_branding', 'manage_system_setting_branding',
            'view_system_setting_communication', 'manage_system_setting_communication',

            // Notifications (limited)
            'view_any_notification_message', 'view_notification_message',
            'create_notification_message', 'update_notification_message',
            'delete_notification_message', 'execute_notification_send',
            'view_any_notification_delivery', 'view_notification_delivery',

            // Maintenance (view only)
            'view_any_maintenance_setting', 'view_maintenance_setting',

            // Admin panel access
            'access_admin_panel',
        ],
        'manager' => [
            // User management (view + limited edit)
            'view_any_user', 'view_user',
            'manage_user_avatar', 'view_user_system_info',

            // Audit logs (view only)
            'view_any_audit_log', 'view_audit_log',

            // Login activities (view only)
            'view_any_user_login_activity', 'view_user_login_activity',

            // System settings (view only)
            'view_any_system_setting', 'view_system_setting',
            'view_system_setting_branding',

            // Notifications (limited)
            'view_any_notification_message', 'view_notification_message',
            'create_notification_message', 'update_notification_message',
            'view_any_notification_delivery', 'view_notification_delivery',

            // Admin panel access
            'access_admin_panel',
        ],
        'user' => [
            // View own profile
            'view_user',

            // View notifications
            'view_any_notification_message', 'view_notification_message',
            'view_any_notification_delivery', 'view_notification_delivery',

            // Admin panel access
            'access_admin_panel',
        ],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        $developerRole = (string) config('security.developer_role', 'developer');
        $superAdminRole = (string) config('security.superadmin_role', 'super_admin');

        $roleNames = array_keys(config('security.role_hierarchy', []));
        $roleNames[] = $developerRole;
        $roleNames[] = $superAdminRole;

        if (Schema::hasTable('users')) {
            $roleNames = array_merge($roleNames, User::query()->pluck('role')->filter()->unique()->all());
        }

        $roleNames = array_values(array_unique(array_filter($roleNames)));

        foreach ($roleNames as $roleName) {
            Role::findOrCreate($roleName, $guard);
        }

        if ($panel = Filament::getDefaultPanel()) {
            Filament::setCurrentPanel($panel);
        }

        // Get Filament Shield permissions
        $resourcePermissions = FilamentShield::getAllResourcePermissionsWithLabels();
        $pagePermissions = collect(FilamentShield::getPages() ?? [])
            ->flatMap(fn (array $page): array => array_keys($page['permissions'] ?? []))
            ->all();
        $widgetPermissions = collect(FilamentShield::getWidgets() ?? [])
            ->flatMap(fn (array $widget): array => array_keys($widget['permissions'] ?? []))
            ->all();
        $shieldCustomPermissions = array_keys(FilamentShield::getCustomPermissions() ?? []);

        // Merge all permissions including custom ones
        $permissions = collect($resourcePermissions)
            ->keys()
            ->merge($pagePermissions)
            ->merge($widgetPermissions)
            ->merge($shieldCustomPermissions)
            ->merge($this->customPermissions)
            ->unique()
            ->values()
            ->all();

        // Create all permissions
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guard,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Developer gets ALL permissions
        $developer = Role::where('name', $developerRole)
            ->where('guard_name', $guard)
            ->first();

        if ($developer) {
            $developer->syncPermissions($permissions);
        }

        // Assign permissions to other roles based on rolePermissions mapping
        foreach ($this->rolePermissions as $roleName => $rolePerms) {
            $role = Role::where('name', $roleName)
                ->where('guard_name', $guard)
                ->first();

            if ($role) {
                // Filter to only permissions that exist
                $validPerms = collect($rolePerms)
                    ->filter(fn (string $p): bool => Permission::where('name', $p)->where('guard_name', $guard)->exists())
                    ->values()
                    ->all();

                $role->syncPermissions($validPerms);
            }
        }

        // Sync users with their roles
        User::query()
            ->whereNotNull('role')
            ->each(function (User $user) use ($roleNames): void {
                if ($user->roles()->exists()) {
                    return;
                }

                if (in_array($user->role, $roleNames, true)) {
                    $user->syncRoles([$user->role]);
                }
            });

        // Create default system settings if not exists
        if (Schema::hasTable('system_settings') && ! SystemSetting::query()->exists()) {
            $defaults = SystemSettings::defaults();
            $data = $defaults['data'] ?? [];

            SystemSetting::create([
                // Project settings
                'project_name' => $data['project']['name'] ?? config('app.name', 'System'),
                'project_description' => $data['project']['description'] ?? '',
                'project_url' => $data['project']['url'] ?? config('app.url'),

                // Storage settings
                'storage_primary_disk' => $data['storage']['primary_disk'] ?? 'google',
                'storage_fallback_disk' => $data['storage']['fallback_disk'] ?? 'public',
                'storage_drive_root' => $data['storage']['drive_root'] ?? 'Warex-System',
                'storage_drive_folder_branding' => $data['storage']['drive_folder_branding'] ?? 'branding',
                'storage_drive_folder_favicon' => $data['storage']['drive_folder_favicon'] ?? 'branding',

                // Email settings
                'email_enabled' => $data['notifications']['email']['enabled'] ?? true,
                'email_provider' => $data['notifications']['email']['provider'] ?? 'SMTP',
                'email_recipients' => $data['notifications']['email']['recipients'] ?? [],
                'smtp_mailer' => $data['notifications']['email']['mailer'] ?? 'smtp',
                'smtp_port' => $data['notifications']['email']['smtp_port'] ?? 587,
                'smtp_encryption' => $data['notifications']['email']['smtp_encryption'] ?? 'tls',

                // Telegram settings
                'telegram_enabled' => $data['notifications']['telegram']['enabled'] ?? false,

                // AI settings
                'ai_enabled' => $data['ai']['enabled'] ?? false,
                'ai_provider' => $data['ai']['provider'] ?? 'openai',
                'ai_model' => $data['ai']['model'] ?? 'gpt-4o',
                'ai_max_tokens' => $data['ai']['max_tokens'] ?? 4096,
                'ai_temperature' => $data['ai']['temperature'] ?? 0.30,
                'ai_timeout' => $data['ai']['timeout'] ?? 30,
                'ai_retry_attempts' => $data['ai']['retry_attempts'] ?? 3,
                'ai_rate_limit_rpm' => $data['ai']['rate_limit']['rpm'] ?? 60,
                'ai_rate_limit_tpm' => $data['ai']['rate_limit']['tpm'] ?? 90000,
                'ai_rate_limit_tpd' => $data['ai']['rate_limit']['tpd'] ?? 1000000,
                'ai_failover_enabled' => true,
                'ai_smart_selection' => true,
                'ai_daily_limit' => 10.00,

                // AI Feature toggles
                'ai_feature_security_analysis' => $data['ai']['features']['security_analysis'] ?? true,
                'ai_feature_anomaly_detection' => $data['ai']['features']['anomaly_detection'] ?? true,
                'ai_feature_threat_classification' => $data['ai']['features']['threat_classification'] ?? true,
                'ai_feature_log_summarization' => $data['ai']['features']['log_summarization'] ?? true,
                'ai_feature_smart_alerts' => $data['ai']['features']['smart_alerts'] ?? true,
                'ai_feature_auto_response' => $data['ai']['features']['auto_response'] ?? false,
                'ai_feature_chat_assistant' => $data['ai']['features']['chat_assistant'] ?? false,

                // AI Alert triggers
                'ai_alert_high_risk_score' => 8,
                'ai_alert_suspicious_patterns' => 5,
                'ai_alert_failed_logins' => 10,
                'ai_alert_anomaly_confidence' => 0.85,

                // AI Actions
                'ai_action_auto_block_ip' => false,
                'ai_action_auto_lock_user' => false,
                'ai_action_notify_admin' => true,
                'ai_action_create_incident' => true,
            ]);
        }
    }
}

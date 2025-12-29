<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
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
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guard,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $developer = Role::where('name', $developerRole)
            ->where('guard_name', $guard)
            ->first();

        if ($developer) {
            $developer->syncPermissions($permissions);
        }

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

        if (Schema::hasTable('system_settings') && ! SystemSetting::query()->exists()) {
            $defaults = SystemSettings::defaults();
            SystemSetting::create([
                'data' => $defaults['data'],
                'secrets' => $defaults['secrets'],
            ]);
        }
    }
}

<?php

namespace App\Policies;

use App\Models\MaintenanceSetting;
use App\Models\User;

class MaintenanceSettingPolicy
{
    /**
     * Determine whether the user can view any maintenance settings.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_maintenance_setting')
            || $user->can('view_maintenance_setting');
    }

    /**
     * Determine whether the user can view the maintenance setting.
     */
    public function view(User $user, MaintenanceSetting $setting): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_maintenance_setting')
            || $user->can('view_any_maintenance_setting');
    }

    /**
     * Determine whether the user can create maintenance settings.
     */
    public function create(User $user): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can update the maintenance setting.
     */
    public function update(User $user, MaintenanceSetting $setting): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('update_maintenance_setting');
    }

    /**
     * Determine whether the user can delete the maintenance setting.
     */
    public function delete(User $user, MaintenanceSetting $setting): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can delete any maintenance settings.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage maintenance tokens.
     */
    public function manageTokens(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_maintenance_tokens')
            || $user->can('update_maintenance_setting');
    }

    /**
     * Determine whether the user can toggle maintenance mode.
     */
    public function toggleMaintenance(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('toggle_maintenance')
            || $user->can('update_maintenance_setting');
    }
}

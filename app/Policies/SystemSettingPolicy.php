<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

class SystemSettingPolicy
{
    /**
     * Determine whether the user can view any system settings.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_system_setting')
            || $user->can('view_system_setting');
    }

    /**
     * Determine whether the user can view the system setting.
     */
    public function view(User $user, SystemSetting $setting): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    /**
     * Determine whether the user can create system settings.
     */
    public function create(User $user): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can update the system setting.
     */
    public function update(User $user, SystemSetting $setting): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can delete the system setting.
     */
    public function delete(User $user, SystemSetting $setting): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can delete any system settings.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    // =========================================================================
    // SECTION-SPECIFIC PERMISSIONS
    // =========================================================================

    /**
     * Determine whether the user can view branding settings.
     */
    public function viewBranding(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_system_setting_branding')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    /**
     * Determine whether the user can manage branding settings.
     */
    public function manageBranding(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_system_setting_branding')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can view storage settings.
     */
    public function viewStorage(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_system_setting_storage')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    /**
     * Determine whether the user can manage storage settings.
     */
    public function manageStorage(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_system_setting_storage')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can view communication settings.
     */
    public function viewCommunication(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_system_setting_communication')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    /**
     * Determine whether the user can manage communication settings.
     */
    public function manageCommunication(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_system_setting_communication')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can view AI settings.
     */
    public function viewAI(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_system_setting_ai')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    /**
     * Determine whether the user can manage AI settings.
     */
    public function manageAI(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_system_setting_ai')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can edit secret values (API keys, passwords).
     */
    public function editSecrets(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('edit_system_setting_secrets')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can test SMTP connection.
     */
    public function testSmtp(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('test_system_setting_smtp')
            || $user->can('manage_system_setting_communication')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can test AI connection.
     */
    public function testAI(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('test_system_setting_ai')
            || $user->can('manage_system_setting_ai')
            || $user->can('update_system_setting');
    }

    /**
     * Determine whether the user can restore the system setting.
     */
    public function restore(User $user, SystemSetting $setting): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can restore any system settings.
     */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can force delete the system setting.
     */
    public function forceDelete(User $user, SystemSetting $setting): bool
    {
        return false; // Settings are singleton
    }

    /**
     * Determine whether the user can force delete any system settings.
     */
    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

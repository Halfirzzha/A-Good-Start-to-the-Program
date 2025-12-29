<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_system_setting');
    }

    public function view(User $user, SystemSetting $setting): bool
    {
        return $user->can('view_system_setting') || $user->can('view_any_system_setting');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemSetting $setting): bool
    {
        return $user->can('update_system_setting');
    }

    public function delete(User $user, SystemSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

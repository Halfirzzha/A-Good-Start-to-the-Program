<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    /**
     * Determine whether the user can view any roles.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_role');
    }

    /**
     * Determine whether the user can view the role.
     */
    public function view(User $user, Role $role): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_role') || $user->can('view_any_role');
    }

    /**
     * Determine whether the user can create roles.
     */
    public function create(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('create_role');
    }

    /**
     * Determine whether the user can update the role.
     */
    public function update(User $user, Role $role): bool
    {
        // Developer role is immutable
        if ($this->isImmutableRole($role)) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('update_role');
    }

    /**
     * Determine whether the user can delete the role.
     */
    public function delete(User $user, Role $role): bool
    {
        // Developer role is immutable
        if ($this->isImmutableRole($role)) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_role');
    }

    /**
     * Determine whether the user can delete any roles.
     */
    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_any_role');
    }

    /**
     * Determine whether the user can restore the role.
     */
    public function restore(User $user, Role $role): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_role');
    }

    /**
     * Determine whether the user can restore any roles.
     */
    public function restoreAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_any_role');
    }

    /**
     * Determine whether the user can force delete the role.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        // Developer role is immutable
        if ($this->isImmutableRole($role)) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_role');
    }

    /**
     * Determine whether the user can force delete any roles.
     */
    public function forceDeleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_any_role');
    }

    /**
     * Check if the role is immutable (cannot be modified or deleted).
     */
    private function isImmutableRole(Role $role): bool
    {
        $developerRole = (string) config('security.developer_role', 'developer');
        $superAdminRole = (string) config('security.superadmin_role', 'super_admin');

        return in_array($role->name, [$developerRole, $superAdminRole], true);
    }
}

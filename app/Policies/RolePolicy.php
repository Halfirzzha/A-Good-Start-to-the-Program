<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Role Policy - Enterprise RBAC Authorization
 *
 * This policy controls access to role management:
 * - Developer: Full access EXCEPT creating new roles
 * - SuperAdmin: Full access to all role operations
 * - Other roles: Based on assigned permissions
 *
 * Immutable roles (developer, super_admin) cannot be modified or deleted.
 */
class RolePolicy
{
    /**
     * Determine whether the user can view any roles.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('view_any_role');
    }

    /**
     * Determine whether the user can view the role.
     */
    public function view(User $user, Role $role): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('view_role') || $user->can('view_any_role');
    }

    /**
     * Determine whether the user can create roles.
     *
     * IMPORTANT: Developer role CANNOT create new roles.
     * This is intentional - only SuperAdmin or users with explicit
     * permission can create roles to maintain security hierarchy.
     */
    public function create(User $user): bool
    {
        // Developer explicitly cannot create roles - this is by design
        // to prevent privilege escalation. Only SuperAdmin or users
        // with explicit create_role permission can create new roles.
        if ($user->isDeveloper() && ! $user->isSuperAdmin()) {
            return false;
        }

        // SuperAdmin can always create roles
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->can('create_role');
    }

    /**
     * Determine whether the user can update the role.
     */
    public function update(User $user, Role $role): bool
    {
        // Immutable roles cannot be modified by anyone
        if ($this->isImmutableRole($role)) {
            return false;
        }

        // Developer can update non-immutable roles
        if ($user->isDeveloper()) {
            return true;
        }

        // SuperAdmin can update non-immutable roles
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->can('update_role');
    }

    /**
     * Determine whether the user can delete the role.
     */
    public function delete(User $user, Role $role): bool
    {
        // Immutable roles cannot be deleted
        if ($this->isImmutableRole($role)) {
            return false;
        }

        // Check if role has users assigned
        if ($role->users()->count() > 0) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->can('delete_role');
    }

    /**
     * Determine whether the user can delete any roles.
     */
    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('delete_any_role');
    }

    /**
     * Determine whether the user can restore the role.
     */
    public function restore(User $user, Role $role): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('restore_role');
    }

    /**
     * Determine whether the user can restore any roles.
     */
    public function restoreAny(User $user): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('restore_any_role');
    }

    /**
     * Determine whether the user can force delete the role.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        // Immutable roles cannot be force deleted
        if ($this->isImmutableRole($role)) {
            return false;
        }

        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('force_delete_role');
    }

    /**
     * Determine whether the user can force delete any roles.
     */
    public function forceDeleteAny(User $user): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->can('force_delete_any_role');
    }

    /**
     * Check if the role is immutable (cannot be modified or deleted).
     *
     * Immutable roles:
     * - developer: System administrator role
     * - super_admin: Highest privilege role
     */
    private function isImmutableRole(Role $role): bool
    {
        $developerRole = (string) config('security.developer_role', 'developer');
        $superAdminRole = (string) config('security.superadmin_role', 'super_admin');

        return in_array($role->name, [$developerRole, $superAdminRole], true);
    }
}

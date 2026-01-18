<?php

namespace App\Policies;

use App\Models\User;

/**
 * Enterprise-grade User Policy with Granular RBAC.
 *
 * Permission Structure:
 * - view_any_user: Can see user list
 * - view_user: Can view user details
 * - create_user: Can create new users
 * - update_user: Can update user basic info
 * - delete_user: Can soft delete users
 * - restore_user: Can restore soft-deleted users
 * - force_delete_user: Can permanently delete users
 *
 * Granular Field-Level Permissions:
 * - user.view.sensitive: Can view sensitive fields (email, phone, IP, login history)
 * - user.edit.sensitive: Can edit sensitive fields (email, phone, password)
 * - user.view.security: Can view security info (2FA, sessions, stamps)
 * - user.edit.security: Can edit security settings (2FA, force password reset)
 * - user.view.roles: Can view role assignments
 * - user.edit.roles: Can change role assignments
 * - user.view.status: Can view account status
 * - user.edit.status: Can change account status (block, suspend)
 * - user.impersonate: Can impersonate other users
 * - user.force_logout: Can force logout other users
 * - user.reset_2fa: Can reset 2FA for other users
 */
class UserPolicy
{
    // =========================================================================
    // Core CRUD Policies
    // =========================================================================

    public function viewAny(User $user): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('view_any_user');
    }

    public function view(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self-view always allowed
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('view_user') || $user->can('view_any_user');
    }

    public function create(User $user): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('create_user');
    }

    public function update(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self-update always allowed for basic fields
        if ($user->id === $model->id) {
            return true;
        }

        if (! $user->can('update_user')) {
            return false;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        // Never delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        if (! $user->can('delete_user')) {
            return false;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return true;
    }

    public function deleteAny(User $user): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('delete_any_user');
    }

    public function restore(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        if (! $user->can('restore_user')) {
            return false;
        }

        return $this->canManageTarget($user, $model);
    }

    public function restoreAny(User $user): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('restore_any_user');
    }

    public function forceDelete(User $user, User $model): bool
    {
        // Never force delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        if (! $user->can('force_delete_user')) {
            return false;
        }

        return $this->canManageTarget($user, $model);
    }

    public function forceDeleteAny(User $user): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('force_delete_any_user');
    }

    // =========================================================================
    // Granular Field-Level Permissions
    // =========================================================================

    /**
     * Can view sensitive fields (email, phone, IP addresses, login history details).
     */
    public function viewSensitive(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self always can see own sensitive data
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->view($user, $model)) {
            return false;
        }

        return $user->can('user.view.sensitive');
    }

    /**
     * Can edit sensitive fields (email, phone, password for others).
     */
    public function editSensitive(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can edit own sensitive data
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->update($user, $model)) {
            return false;
        }

        return $user->can('user.edit.sensitive');
    }

    /**
     * Can view security information (2FA status, sessions, security stamps).
     */
    public function viewSecurity(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can see own security info
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->view($user, $model)) {
            return false;
        }

        return $user->can('user.view.security');
    }

    /**
     * Can edit security settings (2FA, force password change, security stamp).
     */
    public function editSecurity(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can manage own 2FA
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->update($user, $model)) {
            return false;
        }

        return $user->can('user.edit.security');
    }

    /**
     * Can view role assignments.
     */
    public function viewRoles(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can see own roles
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->view($user, $model)) {
            return false;
        }

        return $user->can('user.view.roles') || $user->can('assign_roles');
    }

    /**
     * Can change role assignments.
     */
    public function editRoles(User $user, User $model): bool
    {
        // Cannot change developer role unless you're developer
        if ($model->isDeveloper() && ! $user->isDeveloper()) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        // Cannot change super_admin role unless you're developer
        if ($model->isSuperAdmin() && ! $user->isDeveloper()) {
            return false;
        }

        if (! $this->update($user, $model)) {
            return false;
        }

        return $user->can('user.edit.roles') || $user->can('assign_roles');
    }

    /**
     * Can view account status (active, blocked, suspended).
     */
    public function viewStatus(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can see own status
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->view($user, $model)) {
            return false;
        }

        return $user->can('user.view.status') || $user->can('manage_user_access_status');
    }

    /**
     * Can change account status (block, suspend, terminate, activate).
     */
    public function editStatus(User $user, User $model): bool
    {
        // Cannot change own status
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        if (! $this->update($user, $model)) {
            return false;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('user.edit.status') || $user->can('manage_user_access_status');
    }

    // =========================================================================
    // Special Action Permissions
    // =========================================================================

    /**
     * Can impersonate another user.
     */
    public function impersonate(User $user, User $model): bool
    {
        // Never impersonate yourself
        if ($user->id === $model->id) {
            return false;
        }

        // Only developers can impersonate developers
        if ($model->isDeveloper() && ! $user->isDeveloper()) {
            return false;
        }

        // Only developers can impersonate super_admins
        if ($model->isSuperAdmin() && ! $user->isDeveloper()) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('user.impersonate');
    }

    /**
     * Can force logout another user (revoke all sessions).
     */
    public function forceLogout(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can logout own sessions
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('user.force_logout') || $user->can('execute_user_revoke_sessions');
    }

    /**
     * Can reset 2FA for another user.
     */
    public function reset2fa(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        // Self can manage own 2FA
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('user.reset_2fa') || $user->can('user.edit.security');
    }

    /**
     * Can unlock a locked account.
     */
    public function unlock(User $user, User $model): bool
    {
        // Cannot unlock yourself (security measure)
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('execute_user_unlock');
    }

    /**
     * Can force password reset for another user.
     */
    public function forcePasswordReset(User $user, User $model): bool
    {
        if ($user->hasElevatedPrivileges()) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('execute_user_force_password_reset');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if actor can manage target based on role hierarchy.
     */
    private function canManageTarget(User $actor, User $target): bool
    {
        // Developers are protected from non-developers
        if ($target->isDeveloper() && ! $actor->isDeveloper()) {
            return false;
        }

        // Super admins are protected from non-developers
        if ($target->isSuperAdmin() && ! $actor->isDeveloper()) {
            return false;
        }

        // Check role hierarchy
        return $actor->getRoleRank() > $target->getRoleRank()
            || $actor->isDeveloper()
            || $actor->id === $target->id;
    }

    /**
     * Check if this is a self-operation.
     */
    public function isSelf(User $user, User $model): bool
    {
        return $user->id === $model->id;
    }
}

<?php

namespace App\Policies;

use App\Models\MaintenanceToken;
use App\Models\User;

class MaintenanceTokenPolicy
{
    /**
     * Determine whether the user can view any maintenance tokens.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_maintenance_token')
            || $user->can('view_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can view the maintenance token.
     */
    public function view(User $user, MaintenanceToken $token): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can view their own tokens
        if ($token->created_by === $user->id) {
            return true;
        }

        return $user->can('view_maintenance_token')
            || $user->can('view_any_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can create maintenance tokens.
     */
    public function create(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('create_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can update the maintenance token.
     */
    public function update(User $user, MaintenanceToken $token): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('update_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can delete the maintenance token.
     */
    public function delete(User $user, MaintenanceToken $token): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can delete their own tokens
        if ($token->created_by === $user->id) {
            return $user->can('delete_maintenance_token')
                || $user->can('manage_maintenance_tokens');
        }

        return $user->can('delete_any_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can delete any maintenance tokens.
     */
    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_any_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can revoke the maintenance token.
     */
    public function revoke(User $user, MaintenanceToken $token): bool
    {
        return $this->update($user, $token);
    }

    /**
     * Determine whether the user can rotate the maintenance token.
     */
    public function rotate(User $user, MaintenanceToken $token): bool
    {
        return $this->update($user, $token);
    }

    /**
     * Determine whether the user can restore the maintenance token.
     */
    public function restore(User $user, MaintenanceToken $token): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can restore any maintenance tokens.
     */
    public function restoreAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_any_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can force delete the maintenance token.
     */
    public function forceDelete(User $user, MaintenanceToken $token): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }

    /**
     * Determine whether the user can force delete any maintenance tokens.
     */
    public function forceDeleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_any_maintenance_token')
            || $user->can('manage_maintenance_tokens');
    }
}

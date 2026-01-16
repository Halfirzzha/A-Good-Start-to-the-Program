<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNotification;

class UserNotificationPolicy
{
    /**
     * Determine whether the user can view any user notifications.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Users can always view their own notifications
        return $user->can('view_any_user_notification')
            || $user->can('view_user_notification')
            || true; // All authenticated users can see their inbox
    }

    /**
     * Determine whether the user can view the user notification.
     */
    public function view(User $user, UserNotification $notification): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can view their own notifications
        if ($notification->user_id === $user->id) {
            return true;
        }

        return $user->can('view_user_notification')
            || $user->can('view_any_user_notification');
    }

    /**
     * Determine whether the user can create user notifications.
     */
    public function create(User $user): bool
    {
        return false; // Notifications are created by the system
    }

    /**
     * Determine whether the user can update the user notification.
     */
    public function update(User $user, UserNotification $notification): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can mark their own notifications as read
        if ($notification->user_id === $user->id) {
            return true;
        }

        return $user->can('update_user_notification');
    }

    /**
     * Determine whether the user can delete the user notification.
     */
    public function delete(User $user, UserNotification $notification): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can delete their own notifications
        if ($notification->user_id === $user->id) {
            return $user->can('delete_user_notification') || true; // Allow users to dismiss
        }

        return $user->can('delete_any_user_notification');
    }

    /**
     * Determine whether the user can delete any user notifications.
     */
    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_any_user_notification');
    }

    /**
     * Determine whether the user can restore the user notification.
     */
    public function restore(User $user, UserNotification $notification): bool
    {
        return false; // Notifications are not restorable
    }

    /**
     * Determine whether the user can restore any user notifications.
     */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can force delete the user notification.
     */
    public function forceDelete(User $user, UserNotification $notification): bool
    {
        return false; // Notifications are not force deletable
    }

    /**
     * Determine whether the user can force delete any user notifications.
     */
    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can mark the notification as read.
     */
    public function markAsRead(User $user, UserNotification $notification): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can mark their own notifications as read
        return $notification->user_id === $user->id;
    }

    /**
     * Determine whether the user can mark all notifications as read.
     */
    public function markAllAsRead(User $user): bool
    {
        // All authenticated users can mark their notifications as read
        return true;
    }
}

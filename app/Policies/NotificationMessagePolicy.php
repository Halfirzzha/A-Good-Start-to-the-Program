<?php

namespace App\Policies;

use App\Models\NotificationMessage;
use App\Models\User;

class NotificationMessagePolicy
{
    /**
     * Determine whether the user can view any notification messages.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_notification_message')
            || $user->can('view_notification_message');
    }

    /**
     * Determine whether the user can view the notification message.
     */
    public function view(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can view messages they created
        if ($message->created_by === $user->id) {
            return true;
        }

        return $user->can('view_notification_message')
            || $user->can('view_any_notification_message');
    }

    /**
     * Determine whether the user can create notification messages.
     */
    public function create(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('create_notification_message');
    }

    /**
     * Determine whether the user can update the notification message.
     */
    public function update(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Cannot update sent messages
        if ($message->sent_at !== null) {
            return false;
        }

        // User can update their own drafts
        if ($message->created_by === $user->id) {
            return $user->can('update_notification_message');
        }

        return $user->can('update_any_notification_message');
    }

    /**
     * Determine whether the user can delete the notification message.
     */
    public function delete(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Cannot delete sent messages without special permission
        if ($message->sent_at !== null) {
            return $user->can('delete_sent_notification_message');
        }

        // User can delete their own drafts
        if ($message->created_by === $user->id) {
            return $user->can('delete_notification_message');
        }

        return $user->can('delete_any_notification_message');
    }

    /**
     * Determine whether the user can delete any notification messages.
     */
    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_any_notification_message');
    }

    /**
     * Determine whether the user can send the notification message.
     */
    public function send(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Cannot send already sent messages
        if ($message->sent_at !== null) {
            return false;
        }

        return $user->can('send_notification_message')
            || $user->can('create_notification_message');
    }

    /**
     * Determine whether the user can restore the notification message.
     */
    public function restore(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_notification_message');
    }

    /**
     * Determine whether the user can restore any notification messages.
     */
    public function restoreAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_any_notification_message');
    }

    /**
     * Determine whether the user can force delete the notification message.
     */
    public function forceDelete(User $user, NotificationMessage $message): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_notification_message');
    }

    /**
     * Determine whether the user can force delete any notification messages.
     */
    public function forceDeleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_any_notification_message');
    }
}

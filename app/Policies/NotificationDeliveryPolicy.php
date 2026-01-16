<?php

namespace App\Policies;

use App\Models\NotificationDelivery;
use App\Models\User;

class NotificationDeliveryPolicy
{
    /**
     * Determine whether the user can view any notification deliveries.
     */
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_notification_delivery')
            || $user->can('view_notification_delivery');
    }

    /**
     * Determine whether the user can view the notification delivery.
     */
    public function view(User $user, NotificationDelivery $delivery): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // User can view deliveries for their own notifications
        if ($delivery->user_id === $user->id) {
            return true;
        }

        return $user->can('view_notification_delivery')
            || $user->can('view_any_notification_delivery');
    }

    /**
     * Determine whether the user can create notification deliveries.
     */
    public function create(User $user): bool
    {
        return false; // Deliveries are created by the system
    }

    /**
     * Determine whether the user can update the notification delivery.
     */
    public function update(User $user, NotificationDelivery $delivery): bool
    {
        return false; // Deliveries are immutable
    }

    /**
     * Determine whether the user can delete the notification delivery.
     */
    public function delete(User $user, NotificationDelivery $delivery): bool
    {
        return false; // Deliveries are immutable audit records
    }

    /**
     * Determine whether the user can delete any notification deliveries.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can retry the notification delivery.
     */
    public function retry(User $user, NotificationDelivery $delivery): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Can only retry failed deliveries
        if ($delivery->status !== 'failed') {
            return false;
        }

        return $user->can('retry_notification_delivery')
            || $user->can('update_notification_delivery');
    }
}

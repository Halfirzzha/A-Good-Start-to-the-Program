<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// User-specific private channel
Broadcast::channel('user.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

// Security alerts channel - only for admins and above
Broadcast::channel('security.alerts', function (User $user): bool {
    if ($user->isDeveloper()) {
        return true;
    }

    return $user->hasAnyRole(['super_admin', 'admin'])
        || $user->can('view_security_alerts');
});

// Security sessions channel - only for admins and above
Broadcast::channel('security.sessions', function (User $user): bool {
    if ($user->isDeveloper()) {
        return true;
    }

    return $user->hasAnyRole(['super_admin', 'admin'])
        || $user->can('view_any_user_login_activity');
});

// Audit logs channel - for users with audit log permissions
Broadcast::channel('audit.logs', function (User $user): bool {
    if ($user->isDeveloper()) {
        return true;
    }

    return $user->can('view_any_audit_log')
        || $user->can('view_audit_log');
});

// Admin notifications channel - for admins and above
Broadcast::channel('admin.notifications', function (User $user): bool {
    if ($user->isDeveloper()) {
        return true;
    }

    return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
});

// System settings channel - for users with system settings permissions
Broadcast::channel('system.settings', function (User $user): bool {
    if ($user->isDeveloper()) {
        return true;
    }

    return $user->can('view_any_system_setting')
        || $user->can('view_system_setting');
});

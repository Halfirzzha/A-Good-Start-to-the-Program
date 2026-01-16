<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserLoginActivity;

class UserLoginActivityPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_user_login_activity');
    }

    public function view(User $user, UserLoginActivity $activity): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Users can view their own login activities
        if ($activity->user_id === $user->id) {
            return true;
        }

        return $user->can('view_user_login_activity') || $user->can('view_any_user_login_activity');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserLoginActivity $activity): bool
    {
        return false;
    }

    public function delete(User $user, UserLoginActivity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, UserLoginActivity $activity): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, UserLoginActivity $activity): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

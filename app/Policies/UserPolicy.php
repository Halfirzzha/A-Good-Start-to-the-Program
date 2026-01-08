<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_user');
    }

    public function view(User $user, User $model): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return $user->can('view_user') || $user->can('view_any_user');
    }

    public function create(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('create_user');
    }

    public function update(User $user, User $model): bool
    {
        if ($user->isDeveloper()) {
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
        if ($user->isDeveloper()) {
            return $user->id !== $model->id;
        }

        if (! $user->can('delete_user')) {
            return false;
        }

        if ($user->id === $model->id) {
            return false;
        }

        if (! $this->canManageTarget($user, $model)) {
            return false;
        }

        return true;
    }

    public function deleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('delete_any_user');
    }

    public function restore(User $user, User $model): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        if (! $user->can('restore_user')) {
            return false;
        }

        return $this->canManageTarget($user, $model);
    }

    public function restoreAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('restore_any_user');
    }

    public function forceDelete(User $user, User $model): bool
    {
        if ($user->isDeveloper()) {
            return $user->id !== $model->id;
        }

        if (! $user->can('force_delete_user')) {
            return false;
        }

        if ($user->id === $model->id) {
            return false;
        }

        return $this->canManageTarget($user, $model);
    }

    public function forceDeleteAny(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('force_delete_any_user');
    }

    private function canManageTarget(User $actor, User $target): bool
    {
        if ($target->isDeveloper() && ! $actor->isDeveloper()) {
            return false;
        }

        if ($target->isSuperAdmin() && ! $actor->isDeveloper()) {
            return false;
        }

        return true;
    }
}

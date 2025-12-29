<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isDeveloper($user) && $user->can('view_any_role');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->isDeveloper($user)
            && ($user->can('view_role') || $user->can('view_any_role'));
    }

    public function create(User $user): bool
    {
        return $this->isDeveloper($user) && $user->can('create_role');
    }

    public function update(User $user, Role $role): bool
    {
        if (! $this->isDeveloper($user) || ! $user->can('update_role')) {
            return false;
        }

        return ! $this->isImmutableRole($role);
    }

    public function delete(User $user, Role $role): bool
    {
        if (! $this->isDeveloper($user) || ! $user->can('delete_role')) {
            return false;
        }

        return ! $this->isImmutableRole($role);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeveloper($user) && $user->can('delete_any_role');
    }

    private function isDeveloper(User $user): bool
    {
        return $user->isDeveloper();
    }

    private function isImmutableRole(Role $role): bool
    {
        return $role->name === (string) config('security.developer_role', 'developer');
    }
}

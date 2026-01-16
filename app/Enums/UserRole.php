<?php

namespace App\Enums;

enum UserRole: string
{
    case Developer = 'developer';
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case User = 'user';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Developer->value => 'Developer',
            self::SuperAdmin->value => 'Super Admin',
            self::Admin->value => 'Admin',
            self::Manager->value => 'Manager',
            self::User->value => 'User',
        ];
    }

    /**
     * Get roles that have elevated privileges.
     *
     * @return array<string>
     */
    public static function elevatedRoles(): array
    {
        return [
            self::Developer->value,
            self::SuperAdmin->value,
        ];
    }

    /**
     * Get role hierarchy (higher = more privileges).
     *
     * @return array<string, int>
     */
    public static function hierarchy(): array
    {
        return [
            self::Developer->value => 100,
            self::SuperAdmin->value => 90,
            self::Admin->value => 80,
            self::Manager->value => 70,
            self::User->value => 10,
        ];
    }

    /**
     * Check if this role has elevated privileges.
     */
    public function isElevated(): bool
    {
        return in_array($this->value, self::elevatedRoles(), true);
    }

    /**
     * Get role rank.
     */
    public function rank(): int
    {
        return self::hierarchy()[$this->value] ?? 0;
    }
}

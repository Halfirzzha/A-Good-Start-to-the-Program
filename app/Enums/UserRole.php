<?php

namespace App\Enums;

enum UserRole: string
{
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
            self::SuperAdmin->value => 'Super Admin',
            self::Admin->value => 'Admin',
            self::Manager->value => 'Manager',
            self::User->value => 'User',
        ];
    }
}

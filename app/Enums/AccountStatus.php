<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Active->value => 'Active',
            self::Blocked->value => 'Blocked',
            self::Suspended->value => 'Suspended',
            self::Terminated->value => 'Terminated',
        ];
    }
}

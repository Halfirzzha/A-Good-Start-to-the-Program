<?php

namespace App\Support;

use App\Models\User;

class AuthHelper
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function id(): ?int
    {
        return self::user()?->getAuthIdentifier();
    }
}

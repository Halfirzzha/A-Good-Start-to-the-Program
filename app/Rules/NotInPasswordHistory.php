<?php

namespace App\Rules;

use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class NotInPasswordHistory implements Rule
{
    public function __construct(
        private readonly ?User $user,
        private readonly int $limit,
    ) {
    }

    public function passes($attribute, $value): bool
    {
        if (! $this->user || $this->limit <= 0) {
            return true;
        }

        return ! $this->user->hasUsedPassword((string) $value, $this->limit);
    }

    public function message(): string
    {
        return 'This password was used recently. Please choose a new password.';
    }
}

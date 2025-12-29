<?php

namespace App\Support;

use App\Models\User;
use App\Rules\NotInPasswordHistory;
use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function build(?User $user = null): array
    {
        $minLength = max(8, (int) config('security.password_min_length', 12));

        $rule = Password::min($minLength);

        if (config('security.password_require_mixed', true)) {
            $rule = $rule->mixedCase();
        }

        if (config('security.password_require_numbers', true)) {
            $rule = $rule->numbers();
        }

        if (config('security.password_require_symbols', true)) {
            $rule = $rule->symbols();
        }

        if (config('security.password_require_uncompromised', true)) {
            $threshold = (int) config('security.password_uncompromised_threshold', 0);
            $rule = $rule->uncompromised($threshold);
        }

        $rules = [$rule];

        $history = (int) config('security.password_history', 5);
        if ($user && $history > 0) {
            $rules[] = new NotInPasswordHistory($user, $history);
        }

        return $rules;
    }
}

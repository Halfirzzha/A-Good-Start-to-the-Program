<?php

namespace App\Support;

use App\Models\User;
use App\Rules\NotInPasswordHistory;
use Closure;
use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * Password policy defaults (overridden by config/security.php).
     */
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /**
     * Build comprehensive password validation rules.
     * Enforces: min 3 chars, max 8 chars, must contain letter, number, and symbol.
     *
     * @return array<int, mixed>
     */
    public static function build(?User $user = null): array
    {
        $minLength = (int) config('security.password_min_length', self::MIN_LENGTH);
        $maxLength = (int) config('security.password_max_length', self::MAX_LENGTH);

        $rules = [
            'string',
            'min:' . $minLength,
        ];

        if ($maxLength > 0) {
            $rules[] = 'max:' . $maxLength;
        }

        if (config('security.password_require_letter', false)) {
            $rules[] = self::containsLetter();
        }

        if (config('security.password_require_numbers', false)) {
            $rules[] = self::containsNumber();
        }

        if (config('security.password_require_symbols', false)) {
            $rules[] = self::containsSymbol();
        }

        // Optionally check against compromised password databases
        if (config('security.password_require_uncompromised', false)) {
            $threshold = (int) config('security.password_uncompromised_threshold', 0);
            $rules[] = Password::min($minLength)->uncompromised($threshold);
        }

        // Check password history to prevent reuse
        $history = (int) config('security.password_history', 5);
        if ($user && $history > 0) {
            $rules[] = new NotInPasswordHistory($user, $history);
        }

        return $rules;
    }

    /**
     * Build rules for profile/self password change (same policy).
     *
     * @return array<int, mixed>
     */
    public static function buildForProfile(?User $user = null): array
    {
        return self::build($user);
    }

    /**
     * Build rules for password reset flow.
     *
     * @return array<int, mixed>
     */
    public static function buildForReset(?User $user = null): array
    {
        return self::build($user);
    }

    /**
     * Custom validation: password must contain at least one letter.
     */
    public static function containsLetter(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/[a-zA-Z]/', $value)) {
                $fail(__('validation.password.letter', [
                    'attribute' => $attribute,
                    'default' => 'Password harus mengandung minimal satu huruf.',
                ]));
            }
        };
    }

    /**
     * Custom validation: password must contain at least one number.
     */
    public static function containsNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/[0-9]/', $value)) {
                $fail(__('validation.password.number', [
                    'attribute' => $attribute,
                    'default' => 'Password harus mengandung minimal satu angka.',
                ]));
            }
        };
    }

    /**
     * Custom validation: password must contain at least one symbol.
     */
    public static function containsSymbol(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/[\W_]/', $value)) {
                $fail(__('validation.password.symbol', [
                    'attribute' => $attribute,
                    'default' => 'Password harus mengandung minimal satu simbol (!@#$%^&* dll).',
                ]));
            }
        };
    }

    /**
     * Get human-readable password requirements for UI display.
     */
    public static function requirements(): string
    {
        $min = (int) config('security.password_min_length', self::MIN_LENGTH);
        $max = (int) config('security.password_max_length', self::MAX_LENGTH);
        $needsLetter = (bool) config('security.password_require_letter', false);
        $needsNumber = (bool) config('security.password_require_numbers', false);
        $needsSymbol = (bool) config('security.password_require_symbols', false);

        $parts = [];
        if ($needsLetter) {
            $parts[] = 'huruf';
        }
        if ($needsNumber) {
            $parts[] = 'angka';
        }
        if ($needsSymbol) {
            $parts[] = 'simbol';
        }

        return __('validation.password.requirements', [
            'min' => $min,
            'max' => $max,
            'default' => $parts === []
                ? "Password minimal {$min} karakter."
                : "Password minimal {$min}" . ($max > 0 ? "-{$max}" : '') . " karakter, mengandung " . implode(', ', $parts) . ".",
        ]);
    }

    /**
     * Validate password strength and return detailed feedback.
     *
     * @return array{valid: bool, score: int, feedback: list<string>}
     */
    public static function analyze(string $password): array
    {
        $feedback = [];
        $score = 0;
        $min = (int) config('security.password_min_length', self::MIN_LENGTH);
        $max = (int) config('security.password_max_length', self::MAX_LENGTH);
        $needsLetter = (bool) config('security.password_require_letter', false);
        $needsNumber = (bool) config('security.password_require_numbers', false);
        $needsSymbol = (bool) config('security.password_require_symbols', false);

        $len = strlen($password);

        if ($len < $min) {
            $feedback[] = "Minimal {$min} karakter (saat ini: {$len})";
        } else {
            $score++;
        }

        if ($max > 0 && $len > $max) {
            $feedback[] = "Maksimal {$max} karakter (saat ini: {$len})";
        } else {
            $score++;
        }

        if ($needsLetter) {
            if (! preg_match('/[a-zA-Z]/', $password)) {
                $feedback[] = 'Harus mengandung huruf';
            } else {
                $score++;
            }
        }

        if ($needsNumber) {
            if (! preg_match('/[0-9]/', $password)) {
                $feedback[] = 'Harus mengandung angka';
            } else {
                $score++;
            }
        }

        if ($needsSymbol) {
            if (! preg_match('/[\W_]/', $password)) {
                $feedback[] = 'Harus mengandung simbol';
            } else {
                $score++;
            }
        }

        return [
            'valid' => empty($feedback),
            'score' => $score,
            'feedback' => $feedback,
        ];
    }
}

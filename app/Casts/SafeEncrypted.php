<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Safe Encrypted Cast - Handles DecryptException gracefully
 *
 * When decryption fails (e.g., APP_KEY changed, corrupted data),
 * this cast returns null instead of throwing an exception.
 * This prevents the application from crashing when viewing records
 * with encrypted data that was encrypted with a different key.
 */
class SafeEncrypted implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning('[SafeEncrypted] Failed to decrypt field', [
                'model' => get_class($model),
                'field' => $key,
                'error' => $e->getMessage(),
            ]);

            // Return null instead of throwing - allows UI to work
            return null;
        }
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString($value);
    }
}

<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\App;

class LocaleHelper
{
    public static function resolveUserLocale(?User $user): string
    {
        $locale = is_string($user?->locale) ? trim($user->locale) : '';

        return $locale !== '' ? $locale : config('app.locale', 'en');
    }

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withLocale(string $locale, Closure $callback)
    {
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}

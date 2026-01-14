<?php

namespace App\Http\Middleware;

use App\Support\LocaleHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = LocaleHelper::resolveUserLocale($user);
        app()->setLocale($locale);

        return $next($request);
    }
}

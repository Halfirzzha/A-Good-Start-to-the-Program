<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UpdateLastSeenMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        $ttl = (int) config('audit.last_seen_ttl_seconds', 300);
        if ($ttl <= 0) {
            return $response;
        }

        $store = config('audit.cache_store');
        try {
            $cache = $store ? Cache::store($store) : Cache::store();
            $cacheKey = 'user:last_seen:' . $user->getAuthIdentifier();

            if (! $cache->add($cacheKey, now()->timestamp, $ttl)) {
                return $response;
            }

            $user->forceFill([
                'last_seen_at' => now(),
                'last_seen_ip' => $request->ip(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}

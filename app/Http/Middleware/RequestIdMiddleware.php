<?php

namespace App\Http\Middleware;

use App\Support\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $requestId = SecurityService::requestId($request);
        $request->headers->set('X-Request-Id', $requestId);
        Log::withContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        $response = $next($request);
        $durationMs = (microtime(true) - $start) * 1000;
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', sprintf('%.2fms', $durationMs));
        $response->headers->set('Server-Timing', sprintf('app;dur=%.2f', $durationMs));

        $slowThreshold = (int) config('observability.slow_request_ms', 0);
        if ($slowThreshold > 0 && $durationMs >= $slowThreshold) {
            $payload = [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ];

            try {
                Log::channel('performance')->warning('slow_request', $payload);
            } catch (\Throwable) {
                Log::warning('slow_request', $payload);
            }
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('X-DNS-Prefetch-Control', 'off');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $csp = "default-src 'self'; "
            ."base-uri 'self'; "
            ."object-src 'none'; "
            ."form-action 'self'; "
            ."img-src 'self' data: blob:; "
            ."font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."worker-src 'self' blob:; "
            ."connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com https://fonts.bunny.net; "
            ."frame-ancestors 'self';";

        if ($request->isSecure()) {
            $csp .= ' upgrade-insecure-requests;';
        }
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}

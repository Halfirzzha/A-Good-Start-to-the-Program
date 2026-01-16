<?php

namespace App\Http\Middleware;

use App\Support\AuditLogWriter;
use App\Support\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecurityStampIsValid
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enforce_session_stamp', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $currentStamp = $user->security_stamp;

        if (blank($currentStamp)) {
            $currentStamp = strtoupper(Str::random(64));
            $user->forceFill(['security_stamp' => $currentStamp])->save();
        }

        $sessionStamp = $session->get('security_stamp');

        if ($sessionStamp && $sessionStamp !== $currentStamp) {
            $requestId = SecurityService::requestId($request);

            AuditLogWriter::writeLoginActivity([
                'user_id' => $user->getAuthIdentifier(),
                'identity' => $user->email ?? $user->username,
                'event' => 'session_invalidated',
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'session_id' => $session->getId(),
                'request_id' => $requestId,
                'context' => [
                    'reason' => 'security_stamp_mismatch',
                ],
                'created_at' => now(),
            ]);

            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            abort(401, 'Session invalidated.');
        }

        if (! $sessionStamp) {
            $session->put('security_stamp', $currentStamp);
        }

        return $next($request);
    }
}

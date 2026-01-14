<?php

use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Models\UserInvitation;
use App\Support\AuditLogWriter;
use App\Support\MaintenanceService;
use App\Support\MaintenanceTokenService;
use App\Support\PasswordRules;
use App\Support\SecurityAlert;
use App\Support\SystemHealth;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

Route::get('/', function () {
    return redirect('/admin');
});

$resolveAllowedOrigin = function (Request $request): string {
    $origin = $request->headers->get('Origin');
    $appUrl = (string) config('app.url');

    if (! $origin) {
        return $appUrl;
    }

    $originHost = parse_url($origin, PHP_URL_HOST);
    $appHost = parse_url($appUrl, PHP_URL_HOST);

    if ($originHost && $appHost && strcasecmp($originHost, $appHost) === 0) {
        return $origin;
    }

    return $appUrl;
};

Route::post('/maintenance/bypass', function (Request $request) {
    $payload = $request->validate([
        'token' => ['required', 'string', 'min:6'],
    ]);

    $token = MaintenanceTokenService::normalizeToken($payload['token']);
    if (! $token) {
        return response()->json(['message' => 'Token tidak valid.'], 403);
    }

    $requestId = $request->headers->get('X-Request-Id');
    $sessionId = $request->hasSession() ? $request->session()->getId() : null;

    $verified = MaintenanceTokenService::verify($token);

    if ($verified) {
        if ($request->hasSession()) {
            $request->session()->put('maintenance_bypass', true);
        }

        AuditLogWriter::writeAudit([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => 'maintenance_bypass_granted',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'url' => $request->fullUrl(),
            'route' => (string) optional($request->route())->getName(),
            'method' => $request->getMethod(),
            'status_code' => 200,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'reason' => 'token_match',
            ],
            'created_at' => now(),
        ]);

        SecurityAlert::dispatch('maintenance_bypass_granted', [
            'title' => 'Maintenance bypass granted',
            'reason' => 'token_match',
        ], $request);

        return response()->json(['status' => 'ok']);
    }

    AuditLogWriter::writeAudit([
        'user_id' => $request->user()?->getAuthIdentifier(),
        'action' => 'maintenance_bypass_denied',
        'auditable_type' => null,
        'auditable_id' => null,
        'old_values' => null,
        'new_values' => null,
        'ip_address' => $request->ip(),
        'user_agent' => (string) $request->userAgent(),
        'url' => $request->fullUrl(),
        'route' => (string) optional($request->route())->getName(),
        'method' => $request->getMethod(),
        'status_code' => 403,
        'request_id' => $requestId,
        'session_id' => $sessionId,
        'duration_ms' => null,
        'context' => [
            'reason' => 'token_mismatch',
        ],
        'created_at' => now(),
    ]);

    SecurityAlert::dispatch('maintenance_bypass_denied', [
        'title' => 'Maintenance bypass denied',
        'reason' => 'token_mismatch',
    ], $request);

    return response()->json(['message' => 'Token tidak valid.'], 403);
})->middleware('throttle:maintenance-bypass');

Route::post('/admin/login', function (Request $request) {
    return redirect('/admin');
})->middleware('throttle:auth-login');

Route::get('/livewire/update', function (Request $request) {
    Log::warning('livewire.update.method_not_allowed', [
        'method' => $request->method(),
        'path' => $request->path(),
        'referer' => $request->headers->get('referer'),
        'user_agent' => (string) $request->userAgent(),
        'ip' => $request->ip(),
        'request_id' => $request->headers->get('X-Request-Id'),
        'user_id' => $request->user()?->getAuthIdentifier(),
    ]);

    $isAjax = $request->expectsJson()
        || $request->headers->has('X-Requested-With')
        || $request->headers->has('X-Livewire');

    if ($isAjax) {
        return response()->json(['message' => 'Method Not Allowed'], 405);
    }

    $fallback = url('/admin');

    return redirect()->to(url()->previous() ?: $fallback);
});

Route::get('/maintenance/status', function (Request $request) use ($resolveAllowedOrigin) {
    $maintenance = MaintenanceService::getSettings();
    $snapshot = MaintenanceService::snapshot($maintenance);
    $noteHtml = MaintenanceService::sanitizeNote($maintenance['note_html'] ?? ($maintenance['note'] ?? null));
    $now = now();

    $payload = [
        'status_label' => $snapshot['status_label'],
        'is_active' => $snapshot['is_active'],
        'is_scheduled' => $snapshot['is_scheduled'],
        'scheduled_active' => $snapshot['scheduled_active'],
        'enabled' => $snapshot['enabled'],
        'mode' => (string) ($maintenance['mode'] ?? 'global'),
        'start_at' => $snapshot['start_at']?->toIso8601String(),
        'end_at' => $snapshot['end_at']?->toIso8601String(),
        'server_now' => $now->toIso8601String(),
        'note_html' => $noteHtml,
        'title' => $maintenance['title'] ?? null,
        'summary' => $maintenance['summary'] ?? null,
        'retry_after' => $snapshot['retry_after'],
        'timezone' => config('app.timezone', 'UTC'),
        'request_id' => $request->headers->get('X-Request-Id'),
        'allow_api' => (bool) ($maintenance['allow_api'] ?? false),
    ];

    $allowedOrigin = $resolveAllowedOrigin($request);

    return response()->json($payload)
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
        ->header('Access-Control-Allow-Origin', $allowedOrigin)
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Vary', 'Origin');
})->middleware('throttle:maintenance-status');

Route::get('/maintenance/stream', function (Request $request) use ($resolveAllowedOrigin) {
    $allowedOrigin = $resolveAllowedOrigin($request);

    $response = Response::stream(function () use ($request): void {
        $start = microtime(true);

        while (microtime(true) - $start < 25) {
            if (connection_aborted()) {
                break;
            }
            $maintenance = MaintenanceService::getSettings();
            $snapshot = MaintenanceService::snapshot($maintenance);
            $noteHtml = MaintenanceService::sanitizeNote($maintenance['note_html'] ?? ($maintenance['note'] ?? null));

            $payload = [
                'status_label' => $snapshot['status_label'],
                'is_active' => $snapshot['is_active'],
                'is_scheduled' => $snapshot['is_scheduled'],
                'scheduled_active' => $snapshot['scheduled_active'],
                'enabled' => $snapshot['enabled'],
                'mode' => (string) ($maintenance['mode'] ?? 'global'),
                'start_at' => $snapshot['start_at']?->toIso8601String(),
                'end_at' => $snapshot['end_at']?->toIso8601String(),
                'server_now' => now()->toIso8601String(),
                'note_html' => $noteHtml,
                'title' => $maintenance['title'] ?? null,
                'summary' => $maintenance['summary'] ?? null,
                'retry_after' => $snapshot['retry_after'],
                'timezone' => config('app.timezone', 'UTC'),
                'request_id' => $request->headers->get('X-Request-Id'),
                'allow_api' => (bool) ($maintenance['allow_api'] ?? false),
            ];

            echo "event: status\n";
            echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
            ob_flush();
            flush();

            usleep(5_000_000);
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
        'Access-Control-Allow-Origin' => $allowedOrigin,
        'Access-Control-Allow-Credentials' => 'true',
    ]);

    return $response;
})->middleware('throttle:maintenance-stream');

Route::get('/health/check', function (Request $request) {
    $results = SystemHealth::run();
    SystemHealth::maybeAlert($results, $request);

    return response()->json($results)->header('Cache-Control', 'no-store');
})->middleware('throttle:health-check')->name('health.check');

Route::get('/health/dashboard', function () {
    return view('health.dashboard', [
        'projectName' => SystemSettings::getValue('project.name', config('app.name')),
    ]);
})->name('health.dashboard')->withoutMiddleware([
    MaintenanceModeMiddleware::class,
]);

Route::get('/invitation/{token}', function (Request $request, string $token) {
    $invitation = UserInvitation::where('token_hash', UserInvitation::hashToken($token))->first();

    if (! $invitation || $invitation->isExpired() || $invitation->isUsed()) {
        abort(404);
    }

    $expiresAt = $invitation->expires_at;
    $formAction = $expiresAt
        ? URL::temporarySignedRoute('invitation.store', $expiresAt, ['token' => $token])
        : URL::signedRoute('invitation.store', ['token' => $token]);

    return view('auth.invitation', [
        'token' => $token,
        'email' => $invitation->user?->email,
        'expiresAt' => $expiresAt,
        'formAction' => $formAction,
    ]);
})->name('invitation.show')->middleware(['signed', 'throttle:invitation']);

Route::post('/invitation/{token}', function (Request $request, string $token) {
    $invitation = UserInvitation::where('token_hash', UserInvitation::hashToken($token))->first();

    if (! $invitation || $invitation->isExpired() || $invitation->isUsed()) {
        abort(404);
    }

    $user = $invitation->user;
    if (! $user) {
        abort(404);
    }

    $request->validate([
        'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user->id)],
        'password' => array_merge(['required', 'confirmed'], PasswordRules::build($user)),
    ]);

    $user->forceFill([
        'username' => $request->input('username'),
        'password' => $request->input('password'),
        'email_verified_at' => now(),
        'must_change_password' => false,
        'password_changed_at' => now(),
        'password_changed_by' => $user->getAuthIdentifier(),
    ])->save();

    $invitation->forceFill([
        'used_at' => now(),
    ])->save();

    AuditLogWriter::writeAudit([
        'user_id' => $user->getAuthIdentifier(),
        'action' => 'invitation_accepted',
        'auditable_type' => UserInvitation::class,
        'auditable_id' => $invitation->getKey(),
        'old_values' => null,
        'new_values' => null,
        'ip_address' => $request->ip(),
        'user_agent' => (string) $request->userAgent(),
        'url' => $request->fullUrl(),
        'route' => (string) optional($request->route())->getName(),
        'method' => $request->getMethod(),
        'status_code' => 200,
        'request_id' => $request->headers->get('X-Request-Id'),
        'session_id' => $request->hasSession() ? $request->session()->getId() : null,
        'duration_ms' => null,
        'context' => [
            'email' => $user->email,
        ],
        'created_at' => now(),
    ]);

    SecurityAlert::dispatch('invitation_accepted', [
        'title' => 'Invitation accepted',
        'email' => $user->email,
        'username' => $user->username,
    ], $request);

    return redirect('/admin/login')->with('status', 'Akun berhasil diaktifkan. Silakan login.');
})->name('invitation.store')->middleware(['signed', 'throttle:invitation']);

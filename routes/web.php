<?php

use App\Models\UserInvitation;
use App\Support\AuditLogWriter;
use App\Support\PasswordRules;
use App\Support\SecurityAlert;
use App\Support\SystemHealth;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\MaintenanceModeMiddleware;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/maintenance/bypass', function (Request $request) {
    $payload = $request->validate([
        'token' => ['required', 'string', 'min:6'],
    ]);

    $settings = SystemSettings::get();
    $tokens = Arr::get($settings, 'secrets.maintenance.bypass_tokens', []);
    $tokens = is_array($tokens) ? $tokens : [];

    $requestId = $request->headers->get('X-Request-Id');
    $sessionId = $request->hasSession() ? $request->session()->getId() : null;

    foreach ($tokens as $hash) {
        if (! is_string($hash) || $hash === '') {
            continue;
        }

        if (Hash::check($payload['token'], $hash)) {
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

Route::get('/maintenance/status', function (Request $request) {
    $settings = SystemSettings::get();
    $maintenance = Arr::get($settings, 'data.maintenance', []);

    $parseDate = static function (mixed $value): ?Carbon {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    };

    $now = now();
    $startAt = $parseDate($maintenance['start_at'] ?? null);
    $endAt = $parseDate($maintenance['end_at'] ?? null);

    $scheduledActive = $startAt && $now->greaterThanOrEqualTo($startAt);
    if ($scheduledActive && $endAt) {
        $scheduledActive = $now->lessThanOrEqualTo($endAt);
    }

    $isActive = (bool) ($maintenance['enabled'] ?? false) || $scheduledActive;
    $statusLabel = 'Disabled';
    if ($isActive) {
        $statusLabel = 'Active';
    } elseif ($startAt && $now->lessThan($startAt)) {
        $statusLabel = 'Scheduled';
    }

    $retryAfter = null;
    if ($endAt) {
        $seconds = $now->diffInSeconds($endAt, false);
        if ($seconds > 0) {
            $retryAfter = $seconds;
        }
    }

    $payload = [
        'status_label' => $statusLabel,
        'is_active' => $isActive,
        'is_scheduled' => $startAt && $now->lessThan($startAt),
        'scheduled_active' => $scheduledActive,
        'enabled' => (bool) ($maintenance['enabled'] ?? false),
        'mode' => (string) ($maintenance['mode'] ?? 'global'),
        'start_at' => $startAt?->toIso8601String(),
        'end_at' => $endAt?->toIso8601String(),
        'server_now' => $now->toIso8601String(),
        'note' => $maintenance['note'] ?? null,
        'title' => $maintenance['title'] ?? null,
        'summary' => $maintenance['summary'] ?? null,
        'retry_after' => $retryAfter,
        'timezone' => config('app.timezone', 'UTC'),
        'request_id' => $request->headers->get('X-Request-Id'),
        'allow_api' => (bool) ($maintenance['allow_api'] ?? false),
    ];

    return response()->json($payload)->header('Cache-Control', 'no-store');
});

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
})->name('invitation.show')->middleware('signed');

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
})->name('invitation.store')->middleware('signed');

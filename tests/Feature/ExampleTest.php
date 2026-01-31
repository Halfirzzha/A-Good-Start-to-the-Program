<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditLogMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Clear all security blocks
        Cache::flush();

        $this->withoutMiddleware([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuditLogMiddleware::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureSecurityStampIsValid::class,
        ]);

        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }

    public function test_logout_writes_audit_with_session_context(): void
    {
        Cache::flush();

        $this->withoutMiddleware([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuditLogMiddleware::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureSecurityStampIsValid::class,
        ]);

        Route::middleware('web')->post('/_test/logout', function (Request $request) {
            Auth::logout();
            return response()->json(['ok' => true]);
        });

        $user = User::factory()->create();
        $this->actingAs($user);

        $token = 'test-token';
        $response = $this->withSession(['_token' => $token])
            ->post('/_test/logout', ['reason' => 'test_logout', '_token' => $token]);
        $response->assertOk();

        $row = DB::table('audit_logs')
            ->where('action', 'auth_logout')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);

        $context = $this->decodeJson($row->context);
        $this->assertIsArray($context);
        $this->assertSame('test_logout', $context['reason'] ?? null);
        $this->assertNotEmpty($context['session_id'] ?? null);
        $this->assertNotEmpty($context['ip_address'] ?? null);
        $this->assertNotEmpty($context['user_agent'] ?? null);
    }

    public function test_admin_revoke_sessions_writes_audit_with_reason_and_count(): void
    {
        Cache::flush();

        $this->withoutMiddleware([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuditLogMiddleware::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureSecurityStampIsValid::class,
        ]);

        Route::middleware('web')->post('/_test/revoke-sessions', function (Request $request) {
            $target = User::query()->findOrFail((int) $request->input('target_id'));
            $target->rotateSecurityStamp('admin_revoke_sessions', 2);

            return response()->json(['ok' => true]);
        });

        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor);

        $token = 'test-token';
        $response = $this->withSession(['_token' => $token])
            ->post('/_test/revoke-sessions', ['target_id' => $target->id, '_token' => $token]);
        $response->assertOk();

        $row = DB::table('audit_logs')
            ->where('action', 'session_all_revoked')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($target->id, $row->auditable_id);

        $context = $this->decodeJson($row->context);
        $this->assertIsArray($context);
        $this->assertSame('admin_revoke_sessions', $context['reason'] ?? null);
        $this->assertSame(2, $context['revoked_count'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditLogMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Cache;
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
        ]);

        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}

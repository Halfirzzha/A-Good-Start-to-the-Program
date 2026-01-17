<?php

namespace Tests\Feature;

use App\Models\MaintenanceToken;
use App\Models\MaintenanceSetting;
use App\Support\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaintenanceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        MaintenanceService::forget();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        MaintenanceService::forget();
        parent::tearDown();
    }

    private function seedSettings(array $data = []): MaintenanceSetting
    {
        // Clear all existing settings first
        MaintenanceSetting::query()->truncate();
        Cache::flush();
        MaintenanceService::forget();

        $setting = MaintenanceSetting::query()->create($data);

        Cache::flush();
        MaintenanceService::forget();

        return $setting;
    }

    public function test_status_returns_disabled_when_maintenance_off(): void
    {
        $this->seedSettings([
            'enabled' => false,
        ]);

        $response = $this->getJson('/maintenance/status');

        $response->assertOk()
            ->assertJson([
                'status_label' => 'Disabled',
                'is_active' => false,
                'enabled' => false,
            ])
            ->assertJsonStructure([
                'status_label',
                'is_active',
                'server_now',
                'timezone',
            ]);
    }

    public function test_status_returns_active_when_maintenance_enabled(): void
    {
        $now = Carbon::now();
        $setting = $this->seedSettings([
            'enabled' => true,
            'start_at' => $now->copy()->subHour(),
            'end_at' => $now->copy()->addHour(),
        ]);

        // Force reload settings from database
        Cache::flush();
        MaintenanceService::forget();

        // Verify the setting is saved
        $this->assertTrue($setting->enabled);
        $this->assertNotNull(MaintenanceSetting::query()->where('enabled', true)->first());

        $response = $this->getJson('/maintenance/status');

        $response->assertOk()
            ->assertJson([
                'status_label' => 'Active',
                'is_active' => true,
                'enabled' => true,
            ]);
    }

    public function test_bypass_rejects_invalid_token(): void
    {
        $this->seedSettings();
        MaintenanceToken::query()->create([
            'name' => 'Primary',
            'token_hash' => Hash::make('goodtoken'),
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-token')
            ->postJson('/maintenance/bypass', [
                'token' => 'badtoken',
            ]);

        $response->assertStatus(403);
    }

    public function test_bypass_accepts_valid_token(): void
    {
        $this->seedSettings();
        MaintenanceToken::query()->create([
            'name' => 'Primary',
            'token_hash' => Hash::make('goodtoken'),
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-token')
            ->postJson('/maintenance/bypass', [
                'token' => 'goodtoken',
            ]);

        $response->assertOk();
        $this->assertTrue(session()->get('maintenance_bypass') === true);
    }
}

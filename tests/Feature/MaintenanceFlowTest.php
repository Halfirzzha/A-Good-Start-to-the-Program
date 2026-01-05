<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\MaintenanceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaintenanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(array $data = [], array $secrets = []): SystemSetting
    {
        return SystemSetting::query()->create([
            'data' => $data,
            'secrets' => $secrets,
        ]);
    }

    public function test_status_returns_disabled_when_maintenance_off(): void
    {
        $this->seedSettings([
            'maintenance' => [
                'enabled' => false,
            ],
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
        $this->seedSettings([
            'maintenance' => [
                'enabled' => true,
                'start_at' => $now->copy()->subHour()->toIso8601String(),
                'end_at' => $now->copy()->addHour()->toIso8601String(),
            ],
        ]);

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

        $response = $this->postJson('/maintenance/bypass', [
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

        $response = $this->postJson('/maintenance/bypass', [
            'token' => 'goodtoken',
        ]);

        $response->assertOk();
        $this->assertTrue(session()->get('maintenance_bypass') === true);
    }
}

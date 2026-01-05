<?php

namespace Tests\Unit;

use App\Support\MaintenanceService;
use Carbon\Carbon;
use Tests\TestCase;

class MaintenanceServiceTest extends TestCase
{
    public function test_snapshot_marks_scheduled_and_active_correctly(): void
    {
        $now = Carbon::parse('2026-01-02 12:00:00');
        $maintenance = [
            'enabled' => false,
            'start_at' => '2026-01-02 10:00:00',
            'end_at' => '2026-01-02 14:00:00',
        ];

        $snapshot = MaintenanceService::snapshot($maintenance, $now);

        $this->assertTrue($snapshot['is_active']);
        $this->assertTrue($snapshot['scheduled_active']);
        $this->assertSame('Active', $snapshot['status_label']);
    }

    public function test_snapshot_marks_scheduled_before_start(): void
    {
        $now = Carbon::parse('2026-01-02 08:00:00');
        $maintenance = [
            'enabled' => false,
            'start_at' => '2026-01-02 10:00:00',
            'end_at' => '2026-01-02 14:00:00',
        ];

        $snapshot = MaintenanceService::snapshot($maintenance, $now);

        $this->assertFalse($snapshot['is_active']);
        $this->assertSame('Scheduled', $snapshot['status_label']);
    }
}

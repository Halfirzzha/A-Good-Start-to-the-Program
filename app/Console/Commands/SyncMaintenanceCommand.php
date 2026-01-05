<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Support\AuditLogWriter;
use App\Support\MaintenanceService;
use App\Support\SystemSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SyncMaintenanceCommand extends Command
{
    protected $signature = 'maintenance:sync';

    protected $description = 'Synchronize maintenance enabled flag based on schedule.';

    public function handle(): int
    {
        $settings = SystemSettings::get(true);
        $maintenance = Arr::get($settings, 'data.maintenance', []);

        $snapshot = MaintenanceService::snapshot($maintenance);
        $startAt = $snapshot['start_at'];
        $endAt = $snapshot['end_at'];

        if (! $startAt && ! $endAt) {
            return self::SUCCESS;
        }

        $enabled = (bool) ($maintenance['enabled'] ?? false);

        if ($snapshot['scheduled_active'] && ! $enabled) {
            $this->setEnabled(true, $maintenance, 'schedule_start');
        }

        if (! $snapshot['scheduled_active'] && $endAt && $enabled && now()->greaterThan($endAt)) {
            $this->setEnabled(false, $maintenance, 'schedule_end');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     */
    private function setEnabled(bool $enabled, array $maintenance, string $reason): void
    {
        $setting = SystemSetting::query()->first();
        if (! $setting) {
            return;
        }

        $data = is_array($setting->data) ? $setting->data : [];
        Arr::set($data, 'maintenance.enabled', $enabled);
        $setting->forceFill(['data' => $data])->save();

        AuditLogWriter::writeAudit([
            'user_id' => null,
            'action' => $enabled ? 'maintenance_auto_enabled' : 'maintenance_auto_disabled',
            'auditable_type' => SystemSetting::class,
            'auditable_id' => $setting->getKey(),
            'old_values' => ['enabled' => ! $enabled],
            'new_values' => ['enabled' => $enabled],
            'ip_address' => null,
            'user_agent' => null,
            'url' => null,
            'route' => null,
            'method' => null,
            'status_code' => null,
            'request_id' => (string) Str::uuid(),
            'session_id' => null,
            'duration_ms' => null,
            'context' => [
                'reason' => $reason,
                'start_at' => $maintenance['start_at'] ?? null,
                'end_at' => $maintenance['end_at'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }
}

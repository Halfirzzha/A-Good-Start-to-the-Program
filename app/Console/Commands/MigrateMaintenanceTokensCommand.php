<?php

namespace App\Console\Commands;

use App\Models\MaintenanceToken;
use App\Models\SystemSetting;
use App\Support\AuditLogWriter;
use App\Support\SecurityService;
use App\Support\SystemSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MigrateMaintenanceTokensCommand extends Command
{
    protected $signature = 'maintenance:tokens:migrate {--clear : Hapus token legacy dari secrets setelah migrasi}';

    protected $description = 'Migrate legacy maintenance bypass tokens from system_settings secrets into maintenance_tokens table.';

    public function handle(): int
    {
        $settings = SystemSettings::get(true);
        $tokens = Arr::get($settings, 'secrets.maintenance.bypass_tokens', []);

        if (! is_array($tokens) || $tokens === []) {
            $this->info('No legacy tokens found.');
            return self::SUCCESS;
        }

        $created = 0;
        foreach ($tokens as $hash) {
            if (! is_string($hash) || $hash === '') {
                continue;
            }

            $exists = MaintenanceToken::query()->where('token_hash', $hash)->exists();
            if ($exists) {
                continue;
            }

            MaintenanceToken::query()->create([
                'name' => 'Legacy Token',
                'token_hash' => $hash,
                'created_by' => null,
            ]);
            $created++;
        }

        if ($created > 0) {
            AuditLogWriter::writeAudit([
                'user_id' => null,
                'action' => 'maintenance_tokens_migrated',
                'auditable_type' => SystemSetting::class,
                'auditable_id' => SystemSetting::query()->value('id'),
                'old_values' => null,
                'new_values' => null,
                'ip_address' => null,
                'user_agent' => null,
                'url' => null,
                'route' => null,
                'method' => null,
                'status_code' => null,
                'request_id' => SecurityService::uuid(),
                'session_id' => null,
                'duration_ms' => null,
                'context' => [
                    'created' => $created,
                ],
                'created_at' => now(),
            ]);
        }

        if ($this->option('clear')) {
            $setting = SystemSetting::query()->first();
            if ($setting) {
                $secrets = is_array($setting->secrets) ? $setting->secrets : [];
                Arr::set($secrets, 'maintenance.bypass_tokens', []);
                $setting->forceFill(['secrets' => $secrets])->save();
                $this->info('Legacy tokens cleared from secrets.');
            }
        }

        $this->info("Migrated {$created} legacy tokens.");

        return self::SUCCESS;
    }
}

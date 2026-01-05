<?php

namespace App\Console\Commands;

use App\Support\MaintenanceTokenService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CreateMaintenanceTokenCommand extends Command
{
    protected $signature = 'maintenance:token:create
        {--name= : Nama token untuk identifikasi}
        {--expires= : Tanggal kadaluarsa (contoh: 2026-01-06 00:33:00 UTC)}';

    protected $description = 'Buat token baru untuk bypass maintenance.';

    public function handle(): int
    {
        $expires = $this->option('expires');
        $expiresAt = null;

        if (is_string($expires) && trim($expires) !== '') {
            try {
                $expiresAt = Carbon::parse($expires);
            } catch (\Throwable) {
                $this->error('Format expires tidak valid. Contoh: "2026-01-06 00:33:00 UTC".');
                return self::FAILURE;
            }
        }

        $result = MaintenanceTokenService::create([
            'name' => $this->option('name'),
            'expires_at' => $expiresAt,
        ]);

        $this->info('Token baru berhasil dibuat.');
        $this->line('TOKEN (simpan segera, tidak akan ditampilkan lagi):');
        $this->line($result['token']);

        return self::SUCCESS;
    }
}

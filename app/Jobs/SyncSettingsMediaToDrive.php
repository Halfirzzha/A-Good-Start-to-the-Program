<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncSettingsMediaToDrive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        public string $key,
        public string $sourceDisk,
        public string $sourcePath,
        public string $targetDisk,
        public string $targetPath
    ) {
    }

    public function handle(): void
    {
        if (! Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
            Log::warning('settings.media.sync_missing_source', [
                'key' => $this->key,
                'source_disk' => $this->sourceDisk,
                'source_path' => $this->sourcePath,
            ]);
            return;
        }

        $setting = SystemSetting::query()->first();
        if (! $setting) {
            return;
        }

        $data = is_array($setting->data) ? $setting->data : [];
        $current = Arr::get($data, 'branding.'.$this->key, []);
        if (! is_array($current)) {
            return;
        }

        if (($current['disk'] ?? null) !== $this->sourceDisk || ($current['path'] ?? null) !== $this->sourcePath) {
            return;
        }

        $stream = Storage::disk($this->sourceDisk)->readStream($this->sourcePath);
        if (! is_resource($stream)) {
            Log::warning('settings.media.sync_stream_failed', [
                'key' => $this->key,
                'source_disk' => $this->sourceDisk,
                'source_path' => $this->sourcePath,
            ]);
            return;
        }

        try {
            Storage::disk($this->targetDisk)->writeStream($this->targetPath, $stream);
        } catch (\Throwable $error) {
            Log::warning('settings.media.sync_write_failed', [
                'key' => $this->key,
                'target_disk' => $this->targetDisk,
                'target_path' => $this->targetPath,
                'error' => $error->getMessage(),
            ]);

            throw $error;
        } finally {
            fclose($stream);
        }

        $current['disk'] = $this->targetDisk;
        $current['path'] = $this->targetPath;
        $current['fallback_disk'] = $this->sourceDisk;
        $current['fallback_path'] = $this->sourcePath;
        $current['status'] = 'synced';
        $current['updated_at'] = now()->toIso8601String();

        Arr::set($data, 'branding.'.$this->key, $current);

        $setting->data = $data;
        $setting->save();
    }
}

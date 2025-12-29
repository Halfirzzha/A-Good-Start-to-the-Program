<?php

namespace App\Support;

use App\Jobs\SyncSettingsMediaToDrive;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingsMediaStorage
{
    /**
     * @return array<string, mixed>
     */
    public static function storeBrandingAsset(UploadedFile $file, string $key): array
    {
        $key = trim($key);
        $now = now();

        $primaryDisk = (string) SystemSettings::getValue('storage.primary_disk', 'google');
        $fallbackDisk = (string) SystemSettings::getValue('storage.fallback_disk', 'public');
        $baseFolder = (string) SystemSettings::getValue('storage.drive_folder_branding', 'branding');

        $directory = trim($baseFolder, '/').'/'.Str::slug($key);
        $filename = self::makeFilename($key, $file, $now->format('YmdHis'));
        $path = $directory.'/'.$filename;

        $stored = self::putFile($primaryDisk, $directory, $file, $filename);
        if ($stored) {
            return [
                'disk' => $primaryDisk,
                'path' => $path,
                'fallback_disk' => null,
                'fallback_path' => null,
                'status' => 'synced',
                'target_disk' => $primaryDisk,
                'target_path' => $path,
                'updated_at' => $now->toIso8601String(),
            ];
        }

        $fallbackStored = self::putFile($fallbackDisk, $directory, $file, $filename);
        if (! $fallbackStored) {
            throw new \RuntimeException('Unable to store branding asset on any disk.');
        }

        if ($primaryDisk !== $fallbackDisk) {
            SyncSettingsMediaToDrive::dispatch($key, $fallbackDisk, $path, $primaryDisk, $path);
        }

        return [
            'disk' => $fallbackDisk,
            'path' => $path,
            'fallback_disk' => $fallbackDisk,
            'fallback_path' => $path,
            'status' => 'pending',
            'target_disk' => $primaryDisk,
            'target_path' => $path,
            'updated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storeFavicon(UploadedFile $file): array
    {
        $now = now();
        $primaryDisk = (string) SystemSettings::getValue('storage.primary_disk', 'google');
        $fallbackDisk = (string) SystemSettings::getValue('storage.fallback_disk', 'public');
        $baseFolder = (string) SystemSettings::getValue('storage.drive_folder_favicon', 'branding');

        $directory = trim($baseFolder, '/').'/favicon';
        $filename = self::makeFilename('favicon', $file, $now->format('YmdHis'));
        $path = $directory.'/'.$filename;

        $stored = self::putFile($primaryDisk, $directory, $file, $filename);
        if ($stored) {
            return [
                'disk' => $primaryDisk,
                'path' => $path,
                'fallback_disk' => null,
                'fallback_path' => null,
                'status' => 'synced',
                'target_disk' => $primaryDisk,
                'target_path' => $path,
                'updated_at' => $now->toIso8601String(),
            ];
        }

        $fallbackStored = self::putFile($fallbackDisk, $directory, $file, $filename);
        if (! $fallbackStored) {
            throw new \RuntimeException('Unable to store favicon on any disk.');
        }

        if ($primaryDisk !== $fallbackDisk) {
            SyncSettingsMediaToDrive::dispatch('favicon', $fallbackDisk, $path, $primaryDisk, $path);
        }

        return [
            'disk' => $fallbackDisk,
            'path' => $path,
            'fallback_disk' => $fallbackDisk,
            'fallback_path' => $path,
            'status' => 'pending',
            'target_disk' => $primaryDisk,
            'target_path' => $path,
            'updated_at' => $now->toIso8601String(),
        ];
    }

    private static function putFile(string $disk, string $directory, UploadedFile $file, string $filename): bool
    {
        try {
            return (bool) Storage::disk($disk)->putFileAs($directory, $file, $filename);
        } catch (\Throwable $error) {
            Log::warning('settings.media.store_failed', [
                'disk' => $disk,
                'directory' => $directory,
                'filename' => $filename,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private static function makeFilename(string $key, UploadedFile $file, string $suffix): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $base = Str::slug($key);

        return $base.'-'.$suffix.'.'.$extension;
    }
}

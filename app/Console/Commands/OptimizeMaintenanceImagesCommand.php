<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class OptimizeMaintenanceImagesCommand extends Command
{
    protected $signature = 'maintenance:images:optimize
        {--sizes=640,960,1280 : Lebar output (comma-separated)}
        {--quality=82 : Kualitas WebP/JPEG (1-100)}
        {--force : Timpa file hasil jika sudah ada}';

    protected $description = 'Buat versi ringan untuk gambar maintenance (WebP + fallback).';

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('Ekstensi GD tidak tersedia. Aktifkan GD untuk membuat gambar ringan.');
            return self::FAILURE;
        }

        $dir = public_path('assets/maintenance');
        if (! is_dir($dir)) {
            $this->error('Folder assets/maintenance tidak ditemukan.');
            return self::FAILURE;
        }

        $sizes = array_filter(array_map('trim', explode(',', (string) $this->option('sizes'))));
        $sizes = array_unique(array_map('intval', $sizes));
        $sizes = array_filter($sizes, fn (int $size): bool => $size > 0);

        if ($sizes === []) {
            $this->error('Ukuran output tidak valid.');
            return self::FAILURE;
        }

        sort($sizes);
        $quality = max(1, min(100, (int) $this->option('quality')));
        $force = (bool) $this->option('force');

        $patterns = ['*.png', '*.jpg', '*.jpeg'];
        $files = [];
        foreach ($patterns as $pattern) {
            $files = array_merge($files, glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: []);
        }

        if ($files === []) {
            $this->warn('Tidak ada gambar PNG/JPG untuk diproses.');
            return self::SUCCESS;
        }

        $processed = 0;
        $created = 0;

        foreach ($files as $path) {
            $basename = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/-\d+$/', $basename)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $source = $this->loadImage($path, $extension);
            if (! $source) {
                $this->warn('Gagal membaca gambar: ' . $path);
                continue;
            }

            $processed++;
            $width = imagesx($source);
            $height = imagesy($source);

            foreach ($sizes as $targetWidth) {
                if ($width <= $targetWidth) {
                    continue;
                }

                $targetHeight = (int) round($height * ($targetWidth / $width));
                $resized = imagescale($source, $targetWidth, $targetHeight, IMG_BILINEAR_FIXED);
                if (! $resized) {
                    $this->warn('Gagal resize: ' . $path);
                    continue;
                }

                if ($extension === 'png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }

                $destBase = $dir . DIRECTORY_SEPARATOR . $basename . '-' . $targetWidth;
                $webpPath = $destBase . '.webp';
                $fallbackPath = $destBase . ($extension === 'png' ? '.png' : '.jpg');

                if ($force || ! file_exists($webpPath)) {
                    if (@imagewebp($resized, $webpPath, $quality)) {
                        $created++;
                    }
                }

                if ($force || ! file_exists($fallbackPath)) {
                    if ($extension === 'png') {
                        if (@imagepng($resized, $fallbackPath, 6)) {
                            $created++;
                        }
                    } else {
                        if (@imagejpeg($resized, $fallbackPath, $quality)) {
                            $created++;
                        }
                    }
                }

                imagedestroy($resized);
            }

            imagedestroy($source);
        }

        $this->info('Selesai. Gambar diproses: ' . $processed . ', hasil dibuat: ' . $created . '.');

        return self::SUCCESS;
    }

    private function loadImage(string $path, string $extension): mixed
    {
        return match ($extension) {
            'png' => @imagecreatefrompng($path),
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            default => null,
        };
    }
}

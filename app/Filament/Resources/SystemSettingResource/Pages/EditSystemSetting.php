<?php

namespace App\Filament\Resources\SystemSettingResource\Pages;

use App\Filament\Resources\SystemSettingResource;
use App\Support\MaintenanceService;
use App\Support\SettingsMediaStorage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditSystemSetting extends EditRecord
{
    protected static string $resource = SystemSettingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $noteHtml = Arr::get($data, 'data.maintenance.note_html');
        if (! $noteHtml) {
            $legacy = Arr::get($data, 'data.maintenance.note');
            if (is_string($legacy) && $legacy !== '') {
                Arr::set($data, 'data.maintenance.note_html', $legacy);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $uploads = [
            'branding_logo_upload' => 'logo',
            'branding_cover_upload' => 'cover',
        ];

        foreach ($uploads as $field => $key) {
            $file = $data[$field] ?? null;
            if ($file instanceof TemporaryUploadedFile) {
                $meta = SettingsMediaStorage::storeBrandingAsset($file, $key);
                Arr::set($data, 'data.branding.'.$key, $meta);
            }

            unset($data[$field]);
        }

        $favicon = $data['branding_favicon_upload'] ?? null;
        if ($favicon instanceof TemporaryUploadedFile) {
            $meta = SettingsMediaStorage::storeFavicon($favicon);
            Arr::set($data, 'data.branding.favicon', $meta);
        }
        unset($data['branding_favicon_upload']);

        $data['secrets'] = $this->mergeSecrets($data['secrets'] ?? []);

        $noteHtml = Arr::get($data, 'data.maintenance.note_html');
        $sanitized = MaintenanceService::sanitizeNote(is_string($noteHtml) ? $noteHtml : null);
        Arr::set($data, 'data.maintenance.note_html', $sanitized);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeSecrets(array $incoming): array
    {
        $current = is_array($this->record->secrets) ? $this->record->secrets : [];
        $incoming = $this->stripEmptySecrets($incoming);

        if (empty($incoming)) {
            return $current;
        }

        return array_replace_recursive($current, $incoming);
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed>
     */
    private function stripEmptySecrets(array $secrets): array
    {
        foreach ($secrets as $key => $value) {
            if (is_array($value)) {
                $value = $this->stripEmptySecrets($value);
                if ($value === []) {
                    unset($secrets[$key]);
                    continue;
                }
                $secrets[$key] = $value;
                continue;
            }

            if ($value === null || $value === '') {
                unset($secrets[$key]);
            }
        }

        return $secrets;
    }

}

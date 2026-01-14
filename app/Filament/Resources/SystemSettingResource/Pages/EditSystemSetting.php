<?php

namespace App\Filament\Resources\SystemSettingResource\Pages;

use App\Filament\Resources\SystemSettingResource;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\SettingsMediaStorage;
use Filament\Resources\Pages\EditRecord;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditSystemSetting extends EditRecord
{
    protected static string $resource = SystemSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFormActions(): array
    {
        if (! $this->canSubmit()) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->canSubmit()) {
            return [];
        }

        $data = $this->filterByPermissions($data);

        $uploads = [
            'branding_logo_upload' => 'logo',
            'branding_cover_upload' => 'cover',
        ];

        foreach ($uploads as $field => $key) {
            $file = $data[$field] ?? null;
            if ($file instanceof TemporaryUploadedFile) {
                $meta = SettingsMediaStorage::storeBrandingAsset($file, $key);
                $this->applyBrandingMeta($data, $key, $meta);
            }

            unset($data[$field]);
        }

        $favicon = $data['branding_favicon_upload'] ?? null;
        if ($favicon instanceof TemporaryUploadedFile) {
            $meta = SettingsMediaStorage::storeFavicon($favicon);
            $this->applyBrandingMeta($data, 'favicon', $meta);
        }
        unset($data['branding_favicon_upload']);

        $data = $this->preserveSecretFields($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preserveSecretFields(array $data): array
    {
        foreach ($this->secretFields() as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = $this->record->getAttribute($field);
            }
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private function secretFields(): array
    {
        return [
            'smtp_password',
            'telegram_bot_token',
            'google_drive_service_account_json',
            'google_drive_client_id',
            'google_drive_client_secret',
            'google_drive_refresh_token',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterByPermissions(array $data): array
    {
        if (SystemSettingResource::canUpdateSettings()) {
            return $data;
        }

        $originalKeys = array_keys($data);

        $sections = [
            'project' => [
                'project_name',
                'project_description',
                'project_url',
            ],
            'branding' => [
                'branding_logo_upload',
                'branding_cover_upload',
                'branding_favicon_upload',
                'branding_logo_disk',
                'branding_logo_path',
                'branding_logo_fallback_disk',
                'branding_logo_fallback_path',
                'branding_logo_status',
                'branding_logo_updated_at',
                'branding_cover_disk',
                'branding_cover_path',
                'branding_cover_fallback_disk',
                'branding_cover_fallback_path',
                'branding_cover_status',
                'branding_cover_updated_at',
                'branding_favicon_disk',
                'branding_favicon_path',
                'branding_favicon_fallback_disk',
                'branding_favicon_fallback_path',
                'branding_favicon_status',
                'branding_favicon_updated_at',
            ],
            'storage' => [
                'storage_primary_disk',
                'storage_fallback_disk',
                'storage_drive_root',
                'storage_drive_folder_branding',
                'storage_drive_folder_favicon',
                'google_drive_service_account_json',
                'google_drive_client_id',
                'google_drive_client_secret',
                'google_drive_refresh_token',
            ],
            'communication' => [
                'email_enabled',
                'email_provider',
                'email_from_name',
                'email_from_address',
                'email_auth_from_name',
                'email_auth_from_address',
                'email_recipients',
                'smtp_mailer',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_username',
                'smtp_password',
                'telegram_enabled',
                'telegram_chat_id',
                'telegram_bot_token',
            ],
        ];

        $allowed = [];

        if (SystemSettingResource::canManageProjectSettings()) {
            $allowed = array_merge($allowed, $sections['project']);
        }

        if (SystemSettingResource::canManageBrandingSettings()) {
            $allowed = array_merge($allowed, $sections['branding']);
        }

        if (SystemSettingResource::canManageStorageSettings()) {
            $allowed = array_merge($allowed, $sections['storage']);
        }

        if (SystemSettingResource::canManageCommunicationSettings()) {
            $allowed = array_merge($allowed, $sections['communication']);
        }

        if ($allowed === []) {
            return [];
        }

        $filtered = array_intersect_key($data, array_flip($allowed));
        $blocked = array_values(array_diff($originalKeys, array_keys($filtered)));

        if ($blocked !== []) {
            AuditLogWriter::writeAudit([
                'user_id' => AuthHelper::id(),
                'action' => 'unauthorized_field_update',
                'auditable_type' => $this->record?->getMorphClass(),
                'auditable_id' => $this->record?->getKey(),
                'old_values' => null,
                'new_values' => null,
                'context' => [
                    'resource' => 'system_settings',
                    'blocked_fields' => $blocked,
                    'operation' => 'edit',
                ],
                'created_at' => now(),
            ]);
        }

        return $filtered;
    }

    private function canSubmit(): bool
    {
        return SystemSettingResource::canUpdateSettings()
            || SystemSettingResource::canManageProjectSettings()
            || SystemSettingResource::canManageBrandingSettings()
            || SystemSettingResource::canManageStorageSettings()
            || SystemSettingResource::canManageCommunicationSettings();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function applyBrandingMeta(array &$data, string $key, array $meta): void
    {
        $data["branding_{$key}_disk"] = $meta['disk'] ?? null;
        $data["branding_{$key}_path"] = $meta['path'] ?? null;
        $data["branding_{$key}_fallback_disk"] = $meta['fallback_disk'] ?? null;
        $data["branding_{$key}_fallback_path"] = $meta['fallback_path'] ?? null;
        $data["branding_{$key}_status"] = $meta['status'] ?? null;
        $data["branding_{$key}_updated_at"] = $meta['updated_at'] ?? null;
    }

}

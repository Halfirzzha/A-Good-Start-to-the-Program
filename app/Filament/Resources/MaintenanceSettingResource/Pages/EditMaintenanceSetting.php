<?php

namespace App\Filament\Resources\MaintenanceSettingResource\Pages;

use App\Filament\Resources\MaintenanceSettingResource;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\MaintenanceService;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceSetting extends EditRecord
{
    protected static string $resource = MaintenanceSettingResource::class;

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

        if (array_key_exists('note_html', $data)) {
            $data['note_html'] = MaintenanceService::sanitizeNote($data['note_html']);
        }

        foreach (['allow_roles', 'allow_ips', 'allow_paths', 'deny_paths', 'allow_routes', 'deny_routes'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values = $data[$field];
            if (! is_array($values)) {
                $data[$field] = [];
                continue;
            }

            $data[$field] = array_values(array_filter(
                $values,
                fn ($value): bool => is_string($value) && trim($value) !== ''
            ));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterByPermissions(array $data): array
    {
        if (MaintenanceSettingResource::canUpdateSettings()) {
            return $data;
        }

        $originalKeys = array_keys($data);

        if (! MaintenanceSettingResource::canManageSchedule()) {
            unset($data['start_at'], $data['end_at'], $data['retry_after']);
        }

        if (! MaintenanceSettingResource::canManageMessage()) {
            unset($data['title'], $data['summary'], $data['note_html']);
        }

        if (! MaintenanceSettingResource::canManageAccess()) {
            unset(
                $data['enabled'],
                $data['mode'],
                $data['allow_roles'],
                $data['allow_ips'],
                $data['allow_paths'],
                $data['deny_paths'],
                $data['allow_routes'],
                $data['deny_routes'],
                $data['allow_api'],
                $data['allow_developer_bypass'],
            );
        }

        $blocked = array_values(array_diff($originalKeys, array_keys($data)));
        if ($blocked !== []) {
            AuditLogWriter::writeAudit([
                'user_id' => AuthHelper::id(),
                'action' => 'unauthorized_field_update',
                'auditable_type' => $this->record?->getMorphClass(),
                'auditable_id' => $this->record?->getKey(),
                'old_values' => null,
                'new_values' => null,
                'context' => [
                    'resource' => 'maintenance_settings',
                    'blocked_fields' => $blocked,
                    'operation' => 'edit',
                ],
                'created_at' => now(),
            ]);
        }

        return $data;
    }

    private function canSubmit(): bool
    {
        return MaintenanceSettingResource::canUpdateSettings()
            || MaintenanceSettingResource::canManageSchedule()
            || MaintenanceSettingResource::canManageMessage()
            || MaintenanceSettingResource::canManageAccess();
    }
}

<?php

namespace App\Filament\Resources\NotificationMessageResource\Pages;

use App\Filament\Resources\NotificationMessageResource;
use App\Support\AuthHelper;
use Filament\Resources\Pages\EditRecord;

class EditNotificationMessage extends EditRecord
{
    protected static string $resource = NotificationMessageResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = AuthHelper::id();

        unset($data['target_roles'], $data['channels']);

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! $this->record) {
            return $data;
        }

        $data['target_all'] = $this->record->target_all
            || $this->record->targets()->where('target_type', 'all')->exists();

        $data['target_roles'] = $this->record->targets()
            ->where('target_type', 'role')
            ->pluck('target_value')
            ->filter()
            ->values()
            ->all();

        $data['channels'] = $this->record->channels()
            ->where('enabled', true)
            ->pluck('channel')
            ->values()
            ->all();

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->record) {
            return;
        }

        $state = $this->form->getState();
        $roles = $state['target_roles'] ?? [];
        $channels = $state['channels'] ?? [];

        NotificationMessageResource::syncTargets($this->record, (bool) ($state['target_all'] ?? false), $roles);
        NotificationMessageResource::syncChannels($this->record, $channels);
    }
}

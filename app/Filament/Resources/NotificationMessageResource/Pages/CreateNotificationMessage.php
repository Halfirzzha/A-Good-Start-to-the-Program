<?php

namespace App\Filament\Resources\NotificationMessageResource\Pages;

use App\Filament\Resources\NotificationMessageResource;
use App\Support\AuthHelper;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationMessage extends CreateRecord
{
    protected static string $resource = NotificationMessageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = AuthHelper::id();
        $data['updated_by'] = AuthHelper::id();
        $data['status'] = $data['status'] ?? 'draft';

        unset($data['target_roles'], $data['channels']);

        return $data;
    }

    protected function afterCreate(): void
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

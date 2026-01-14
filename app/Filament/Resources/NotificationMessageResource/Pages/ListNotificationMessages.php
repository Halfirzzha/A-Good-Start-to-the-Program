<?php

namespace App\Filament\Resources\NotificationMessageResource\Pages;

use App\Filament\Resources\NotificationMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNotificationMessages extends ListRecords
{
    protected static string $resource = NotificationMessageResource::class;

    protected function getTableHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('notifications.ui.center.actions.new'))
                ->icon('heroicon-o-plus'),
        ];
    }
}

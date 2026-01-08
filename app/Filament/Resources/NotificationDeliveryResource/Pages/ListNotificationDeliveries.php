<?php

namespace App\Filament\Resources\NotificationDeliveryResource\Pages;

use App\Filament\Resources\NotificationDeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

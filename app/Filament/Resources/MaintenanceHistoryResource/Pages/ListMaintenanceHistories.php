<?php

namespace App\Filament\Resources\MaintenanceHistoryResource\Pages;

use App\Filament\Resources\MaintenanceHistoryResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;

class ListMaintenanceHistories extends ListRecords
{
    protected static string $resource = MaintenanceHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}

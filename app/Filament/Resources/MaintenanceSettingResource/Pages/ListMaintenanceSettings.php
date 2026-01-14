<?php

namespace App\Filament\Resources\MaintenanceSettingResource\Pages;

use App\Filament\Resources\MaintenanceSettingResource;
use App\Models\MaintenanceSetting;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceSettings extends ListRecords
{
    protected static string $resource = MaintenanceSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        $record = MaintenanceSetting::query()->latest('id')->first();
        if ($record) {
            $this->redirect(MaintenanceSettingResource::getUrl('edit', ['record' => $record]));
        }
    }
}

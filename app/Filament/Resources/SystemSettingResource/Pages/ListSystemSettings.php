<?php

namespace App\Filament\Resources\SystemSettingResource\Pages;

use App\Filament\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Filament\Resources\Pages\ListRecords;

class ListSystemSettings extends ListRecords
{
    protected static string $resource = SystemSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        $record = SystemSetting::query()->first();
        if ($record) {
            $this->redirect(SystemSettingResource::getUrl('edit', ['record' => $record]));
        }
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

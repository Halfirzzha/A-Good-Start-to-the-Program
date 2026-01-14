<?php

namespace App\Filament\Resources\UnifiedHistoryResource\Pages;

use App\Filament\Resources\UnifiedHistoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListUnifiedHistories extends ListRecords
{
    protected static string $resource = UnifiedHistoryResource::class;

    protected function getTableToolbarActions(): array
    {
        return [
            Action::make('clear_table_state')
                ->label(__('ui.history.unified.actions.clear'))
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->iconButton()
                ->tooltip(__('ui.history.unified.actions.clear_tooltip'))
                ->action('clearTableState'),
        ];
    }

    public function clearTableState(): void
    {
        $this->resetTableFiltersForm();
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }
}

<?php

namespace App\Filament\Resources\SystemSettingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = null;

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('ui.system_settings.versions.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('ui.system_settings.versions.timestamp'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('ui.system_settings.versions.action')),
                TextColumn::make('actor.name')
                    ->label(__('ui.system_settings.versions.actor'))
                    ->default(__('ui.system_settings.versions.default_actor')),
                TextColumn::make('changed_keys')
                    ->label(__('ui.system_settings.versions.changed'))
                    ->formatStateUsing(function ($state): string {
                        $keys = is_array($state) ? $state : [];
                        return $keys === [] ? __('ui.system_settings.versions.none') : implode(', ', $keys);
                    })
                    ->wrap(),
                TextColumn::make('request_id')
                    ->label(__('ui.system_settings.versions.request_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false);
    }
}

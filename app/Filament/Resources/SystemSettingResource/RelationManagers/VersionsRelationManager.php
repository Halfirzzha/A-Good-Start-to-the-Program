<?php

namespace App\Filament\Resources\SystemSettingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Change History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Action'),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->default('System'),
                TextColumn::make('changed_keys')
                    ->label('Changed')
                    ->formatStateUsing(function ($state): string {
                        $keys = is_array($state) ? $state : [];
                        return $keys === [] ? 'n/a' : implode(', ', $keys);
                    })
                    ->wrap(),
                TextColumn::make('request_id')
                    ->label('Request ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false);
    }
}

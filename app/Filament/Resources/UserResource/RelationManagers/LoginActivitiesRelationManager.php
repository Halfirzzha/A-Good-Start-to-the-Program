<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\UserLoginActivity;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

class LoginActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'loginActivities';

    protected static ?string $recordTitleAttribute = 'event';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('primary'),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label('User Agent')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('identity')
                    ->label('Identity')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label('Request ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Event')
                    ->options(fn (): array => UserLoginActivity::query()
                        ->distinct()
                        ->pluck('event', 'event')
                        ->all())
                    ->multiple()
                    ->searchable(),
            ])
            ->emptyStateHeading('Belum ada aktivitas login')
            ->emptyStateDescription('Pengguna ini belum memiliki aktivitas login atau audit yang tercatat.')
            ->emptyStateActions([
                Action::make('refresh')
                    ->label('Segarkan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat detail')
                    ->icon('heroicon-o-eye')
                    ->color('primary'),
            ]);
    }
}

<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\UserLoginActivity;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->label(__('ui.security.login_activity.columns.time'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('primary'),
                TextColumn::make('event')
                    ->label(__('ui.security.login_activity.columns.event'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label(__('ui.security.login_activity.columns.ip'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label(__('ui.security.login_activity.columns.user_agent'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('identity')
                    ->label(__('ui.security.login_activity.columns.identity'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label(__('ui.security.login_activity.columns.request_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('ui.security.login_activity.filters.event'))
                    ->options(fn (): array => UserLoginActivity::query()
                        ->distinct()
                        ->pluck('event', 'event')
                        ->all())
                    ->multiple()
                    ->searchable(),
            ])
            ->emptyStateHeading(__('ui.users.login_activity.empty.heading'))
            ->emptyStateDescription(__('ui.users.login_activity.empty.description'))
            ->emptyStateActions([
                \Filament\Actions\Action::make('refresh')
                    ->label(__('ui.security.login_activity.actions.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('ui.security.login_activity.actions.view'))
                    ->icon('heroicon-o-eye')
                    ->color('primary'),
            ]);
    }
}

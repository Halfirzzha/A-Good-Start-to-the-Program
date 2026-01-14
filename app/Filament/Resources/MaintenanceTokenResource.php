<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceTokenResource\Pages;
use App\Models\MaintenanceToken;
use App\Support\AuthHelper;
use App\Support\MaintenanceTokenService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class MaintenanceTokenResource extends Resource
{
    protected static ?string $model = MaintenanceToken::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.maintenance');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('ui.maintenance.tokens.columns.time'))
                    ->sortable()
                    ->formatStateUsing(fn (MaintenanceToken $record): string => $record->created_at?->diffForHumans() ?? '—')
                    ->description(fn (MaintenanceToken $record): ?string => $record->created_at?->format('d M Y, H:i:s T')),
                TextColumn::make('status')
                    ->label(__('ui.maintenance.tokens.columns.status'))
                    ->badge()
                    ->getStateUsing(fn (MaintenanceToken $record): string => $record->isActive()
                        ? __('ui.maintenance.tokens.status.active')
                        : __('ui.maintenance.tokens.status.revoked'))
                    ->color(fn (MaintenanceToken $record): string => $record->isActive() ? 'success' : 'gray'),
                TextColumn::make('name')
                    ->label(__('ui.maintenance.tokens.columns.token'))
                    ->searchable()
                    ->placeholder(__('ui.maintenance.tokens.placeholders.token'))
                    ->description(function (MaintenanceToken $record): string {
                        $lastUsed = $record->last_used_at
                            ? $record->last_used_at->diffForHumans()
                            : __('ui.maintenance.tokens.placeholders.last_used_never');
                        $expires = $record->expires_at
                            ? $record->expires_at->format('d M Y, H:i T')
                            : __('ui.maintenance.tokens.placeholders.expires_none');
                        return __('ui.maintenance.tokens.meta.last_used', ['value' => $lastUsed])
                            .' · '.__('ui.maintenance.tokens.meta.expires', ['value' => $expires]);
                    })
                    ->limit(36),
                TextColumn::make('creator.name')
                    ->label(__('ui.maintenance.tokens.columns.user'))
                    ->placeholder(__('ui.maintenance.tokens.placeholders.user'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('ui.maintenance.tokens.filters.status'))
                    ->options([
                        'active' => __('ui.maintenance.tokens.status.active'),
                        'revoked' => __('ui.maintenance.tokens.status.revoked'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $value === 'active'
                            ? $query->whereNull('revoked_at')
                            : $query->whereNotNull('revoked_at');
                    }),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->searchable()
            ->persistFiltersInSession()
            ->headerActions([
                Action::make('create_token')
                    ->label(__('ui.maintenance.tokens.actions.create'))
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('name')
                            ->label(__('ui.maintenance.tokens.form.name'))
                            ->maxLength(100),
                        DateTimePicker::make('expires_at')
                            ->label(__('ui.maintenance.tokens.form.expires_at'))
                            ->timezone('UTC')
                            ->seconds(false)
                            ->helperText(__('ui.maintenance.tokens.form.expires_help')),
                    ])
                    ->action(function (array $data): void {
                        if (! self::canManageTokens()) {
                            abort(403);
                        }

                        if (self::isRateLimited('maintenance_token_create', 6, 60)) {
                            Notification::make()
                                ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                ->body(__('ui.maintenance.tokens.notifications.too_many_body_create'))
                                ->warning()
                                ->send();
                            return;
                        }

                        $result = MaintenanceTokenService::create($data, AuthHelper::id());
                        $plain = $result['token'];

                        Notification::make()
                            ->title(__('ui.maintenance.tokens.notifications.created_title'))
                            ->body(new HtmlString(__('ui.maintenance.tokens.notifications.created_body', ['token' => e($plain)])))
                            ->success()
                            ->persistent()
                            ->send();
                    })
                    ->authorize('execute_maintenance_bypass_token')
                    ->visible(fn (): bool => self::canManageTokens()),
            ])
            ->actions([
                Action::make('rotate')
                    ->label(__('ui.maintenance.tokens.actions.rotate'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->authorize('execute_maintenance_bypass_token')
                    ->action(function (MaintenanceToken $record): void {
                        if (! self::canManageTokens()) {
                            abort(403);
                        }

                        if (self::isRateLimited('maintenance_token_rotate', 6, 60)) {
                            Notification::make()
                                ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                ->body(__('ui.maintenance.tokens.notifications.too_many_body_rotate'))
                                ->warning()
                                ->send();
                            return;
                        }

                        $plain = MaintenanceTokenService::rotate($record, AuthHelper::id());
                        Notification::make()
                            ->title(__('ui.maintenance.tokens.notifications.rotated_title'))
                            ->body(new HtmlString(__('ui.maintenance.tokens.notifications.rotated_body', ['token' => e($plain)])))
                            ->success()
                            ->persistent()
                            ->send();
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
                Action::make('revoke')
                    ->label(__('ui.maintenance.tokens.actions.revoke'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('execute_maintenance_bypass_token')
                    ->action(function (MaintenanceToken $record): void {
                        if (! self::canManageTokens()) {
                            abort(403);
                        }

                        if (self::isRateLimited('maintenance_token_revoke', 8, 60)) {
                            Notification::make()
                                ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                ->body(__('ui.maintenance.tokens.notifications.too_many_body_revoke'))
                                ->warning()
                                ->send();
                            return;
                        }

                        MaintenanceTokenService::revoke($record, AuthHelper::id());
                        Notification::make()
                            ->title(__('ui.maintenance.tokens.notifications.revoked_title'))
                            ->success()
                            ->send();
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
                DeleteAction::make()
                    ->label(__('ui.maintenance.tokens.actions.delete'))
                    ->authorize('execute_maintenance_bypass_token')
                    ->before(function (): void {
                        if (! self::canManageTokens()) {
                            abort(403);
                        }
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('creator'))
            ->emptyStateHeading(__('ui.maintenance.tokens.empty.heading'))
            ->emptyStateDescription(__('ui.maintenance.tokens.empty.description'))
            ->toolbarActions([
                Action::make('refresh')
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->iconButton()
                    ->tooltip(__('ui.maintenance.tokens.actions.refresh'))
                    ->action(fn () => redirect()->to(request()->fullUrl())),
            ]);
    }

    public static function canViewAny(): bool
    {
        return self::canManageTokens();
    }

    public static function canCreate(): bool
    {
        return self::canManageTokens();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManageTokens();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceTokens::route('/'),
        ];
    }

    private static function canManageTokens(): bool
    {
        $user = AuthHelper::user();

        return $user
            && $user->isDeveloper()
            && $user->can('execute_maintenance_bypass_token');
    }

    private static function isRateLimited(string $key, int $maxAttempts, int $seconds): bool
    {
        $userId = AuthHelper::id() ?: 'guest';
        $cacheKey = "rate:maintenance_tokens:{$key}:{$userId}";

        $attempts = Cache::increment($cacheKey);
        if ($attempts === 1) {
            Cache::put($cacheKey, 1, now()->addSeconds($seconds));
        }

        return $attempts > $maxAttempts;
    }
}

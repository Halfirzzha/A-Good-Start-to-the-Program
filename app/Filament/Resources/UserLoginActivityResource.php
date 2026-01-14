<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserLoginActivityResource\Pages;
use App\Models\UserLoginActivity;
use App\Support\AuthHelper;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserLoginActivityResource extends Resource
{
    protected static ?string $model = UserLoginActivity::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-finger-print';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.security');
    }

    public static function getModelLabel(): string
    {
        return __('ui.security.login_activity.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.security.login_activity.plural');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label(__('ui.security.login_activity.columns.time'))->dateTime(),
            TextEntry::make('event')->badge(),
            TextEntry::make('user.email')->label(__('ui.security.login_activity.columns.user')),
            TextEntry::make('identity')->label(__('ui.security.login_activity.columns.identity')),
            TextEntry::make('ip_address')->label(__('ui.security.login_activity.columns.ip')),
            TextEntry::make('user_agent')->label(__('ui.security.login_activity.columns.user_agent')),
            TextEntry::make('request_id')->label(__('ui.security.login_activity.columns.request_id')),
            TextEntry::make('session_id')->label(__('ui.security.login_activity.columns.session_id')),
            KeyValueEntry::make('context')
                ->label(__('ui.security.login_activity.columns.context'))
                ->getStateUsing(fn (UserLoginActivity $record): array => self::normalizeKeyValue($record->context)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('created_at')
                            ->label(__('ui.security.login_activity.columns.time'))
                            ->dateTime()
                            ->sortable()
                            ->description(fn (UserLoginActivity $record): ?string => $record->created_at?->diffForHumans()),
                        TextColumn::make('event')
                            ->badge()
                            ->searchable()
                            ->color(fn (string $state): string => match (true) {
                                str_contains($state, 'success') || $state === 'login' => 'success',
                                str_contains($state, 'failed') => 'danger',
                                str_contains($state, 'logout') => 'gray',
                                str_contains($state, 'locked') || str_contains($state, 'blocked') => 'danger',
                                str_contains($state, 'bypass') => 'warning',
                                default => 'info',
                            }),
                    ]),
                    Stack::make([
                        TextColumn::make('user.email')
                            ->label(__('ui.security.login_activity.columns.user'))
                            ->searchable()
                            ->placeholder(__('ui.security.login_activity.placeholders.user')),
                        TextColumn::make('identity')
                            ->label(__('ui.security.login_activity.columns.identity'))
                            ->searchable()
                            ->toggleable(),
                    ])->space(1),
                ])->from('md'),
                TextColumn::make('ip_address')
                    ->label(__('ui.security.login_activity.columns.ip'))
                    ->searchable()
                    ->copyable()
                    ->copyMessage(__('ui.security.login_activity.copy.ip')),
                TextColumn::make('user_agent')
                    ->label(__('ui.security.login_activity.columns.user_agent'))
                    ->limit(40)
                    ->tooltip(fn (UserLoginActivity $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label(__('ui.security.login_activity.columns.request_id'))
                    ->limit(12)
                    ->tooltip(fn (UserLoginActivity $record): ?string => $record->request_id)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('session_id')
                    ->label(__('ui.security.login_activity.columns.session_id'))
                    ->limit(12)
                    ->tooltip(fn (UserLoginActivity $record): ?string => $record->session_id)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('ui.security.login_activity.filters.event'))
                    ->options(fn (): array => UserLoginActivity::query()
                        ->distinct()
                        ->pluck('event', 'event')
                        ->sort()
                        ->all())
                    ->searchable()
                    ->multiple(),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->emptyStateHeading(__('ui.security.login_activity.empty.heading'))
            ->emptyStateDescription(__('ui.security.login_activity.empty.description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('ui.security.login_activity.actions.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->authorize('view')
                    ->visible(fn (UserLoginActivity $record): bool => AuthHelper::user()?->can('view', $record) ?? false),
            ])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('view_any_user_login_activity')
            || $user->can('view_user_login_activity')
            || $user->can('view_any_user_login_activities')
            || $user->can('view_user_login_activities');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserLoginActivities::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed> | null  $values
     * @return array<string, string>
     */
    private static function normalizeKeyValue(?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[$key] = self::stringifyValue($value);
        }

        return $normalized;
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '[' . get_debug_type($value) . ']' : $json;
    }
}

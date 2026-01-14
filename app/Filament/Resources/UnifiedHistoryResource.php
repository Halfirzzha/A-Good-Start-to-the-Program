<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnifiedHistoryResource\Pages;
use App\Models\AuditLog;
use App\Support\AuthHelper;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class UnifiedHistoryResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = null;

    public static function getModelLabel(): string
    {
        return __('ui.history.unified.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.history.unified.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('ui.history.unified.label');
    }

    /**
     * @var array<int, string>
     */
    private static array $allowedActions = [
        'unified_history_entry',
        'unified_history_bootstrap',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make(__('ui.history.unified.sections.summary'))
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('ui.history.unified.columns.time'))
                        ->dateTime(),
                    TextEntry::make('context.category')
                        ->label(__('ui.history.unified.columns.category'))
                        ->badge()
                        ->formatStateUsing(fn ($state): string => self::formatCategory($state)),
                    TextEntry::make('context.scope')
                        ->label(__('ui.history.unified.columns.scope'))
                        ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'scope')))
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'scope')),
                    TextEntry::make('context.title')
                        ->label(__('ui.history.unified.columns.title'))
                        ->wrap()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'title')),
                    TextEntry::make('context.summary')
                        ->label(__('ui.history.unified.columns.summary'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'summary')),
                ])
                ->columns(2),
            \Filament\Schemas\Components\Section::make(__('ui.history.unified.sections.detail'))
                ->schema([
                    TextEntry::make('context.details')
                        ->label(__('ui.history.unified.columns.detail'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'details')),
                    TextEntry::make('context.findings')
                        ->label(__('ui.history.unified.columns.findings'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'findings')),
                    TextEntry::make('context.recommendations')
                        ->label(__('ui.history.unified.columns.recommendations'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'recommendations')),
                    TextEntry::make('context.mitigations')
                        ->label(__('ui.history.unified.columns.mitigations'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'mitigations')),
                    TextEntry::make('context.decisions')
                        ->label(__('ui.history.unified.columns.decisions'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'decisions')),
                    TextEntry::make('context.configuration_changes')
                        ->label(__('ui.history.unified.columns.config_changes'))
                        ->wrap()
                        ->columnSpanFull()
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'configuration_changes')),
                ])
                ->columns(1),
            \Filament\Schemas\Components\Section::make(__('ui.history.unified.sections.traceability'))
                ->schema([
                    TextEntry::make('context.tags')
                        ->label(__('ui.history.unified.columns.tags'))
                        ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'tags')))
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'tags')),
                    TextEntry::make('context.references')
                        ->label(__('ui.history.unified.columns.references'))
                        ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'references')))
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'references')),
                    TextEntry::make('context.related_request_id')
                        ->label(__('ui.history.unified.columns.related_request_id'))
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'related_request_id')),
                    TextEntry::make('context.related_audit_id')
                        ->label(__('ui.history.unified.columns.related_audit_id'))
                        ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'related_audit_id')),
                    TextEntry::make('user.email')
                        ->label(__('ui.history.unified.columns.actor'))
                        ->getStateUsing(fn (AuditLog $record): string => $record->user?->email ?? __('ui.common.system')),
                    TextEntry::make('request_id')->label(__('ui.history.unified.columns.request_id')),
                    TextEntry::make('action')->label(__('ui.history.unified.columns.action'))->badge(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('ui.history.unified.columns.time'))
                    ->since()
                    ->description(fn (AuditLog $record): string => $record->created_at?->format('d M Y, H:i:s') ?? '-')
                    ->sortable(),
                TextColumn::make('context.category')
                    ->label(__('ui.history.unified.columns.category'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatCategory($state))
                    ->sortable(),
                TextColumn::make('context.title')
                    ->label(__('ui.history.unified.columns.title'))
                    ->searchable()
                    ->wrap()
                    ->limit(80)
                    ->formatStateUsing(function (AuditLog $record): string {
                        $title = (string) Arr::get($record->context, 'title');
                        if ($title !== '') {
                            return $title;
                        }

                        return (string) Str::limit((string) Arr::get($record->context, 'summary', '—'), 80);
                    }),
                TextColumn::make('context.summary')
                    ->label(__('ui.history.unified.columns.summary'))
                    ->limit(140)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('user.email')
                    ->label(__('ui.history.unified.columns.user'))
                    ->getStateUsing(function (AuditLog $record): string {
                        $user = $record->user;
                        if (! $user) {
                            return __('ui.common.system');
                        }

                        $role = $user->role ?: $user->getRoleNames()->first();
                        return trim(($user->name ?: $user->email).' '.($role ? "· {$role}" : ''));
                    })
                    ->searchable(),
                TextColumn::make('context.scope')
                    ->label(__('ui.history.unified.columns.scope'))
                    ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'scope')))
                    ->toggleable(),
                IconColumn::make('has_findings')
                    ->label(__('ui.history.unified.columns.findings'))
                    ->boolean()
                    ->getStateUsing(fn (AuditLog $record): bool => (bool) Arr::get($record->context, 'findings'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label(__('ui.history.unified.columns.request_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('action')
                    ->label(__('ui.history.unified.columns.action'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('ui.history.unified.filters.category'))
                    ->options(self::historyCategories())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->where('context->category', $value);
                    }),
                SelectFilter::make('scope')
                    ->label(__('ui.history.unified.filters.scope'))
                    ->options(self::historyScopes())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->whereJsonContains('context->scope', $value);
                    }),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->emptyStateHeading(__('ui.history.unified.empty.heading'))
            ->emptyStateDescription(__('ui.history.unified.empty.description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('ui.history.unified.actions.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->authorize(fn (): bool => AuthHelper::user()?->can('view_unified_history') ?? false),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('action', self::$allowedActions);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnifiedHistories::route('/'),
        ];
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
        return AuthHelper::user()?->can('view_any_unified_history') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return AuthHelper::user()?->can('view_unified_history') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public static function historyCategories(): array
    {
        return [
            'project' => 'Project Improvement',
            'security' => 'Security',
            'deep_scan' => 'Deep Scan',
            'hardening' => 'Hardening',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function historyScopes(): array
    {
        return [
            'code' => 'Code',
            'dependency' => 'Dependency',
            'configuration' => 'Configuration',
            'permission' => 'Permission',
            'architecture' => 'Architecture',
        ];
    }

    private static function formatCategory(mixed $value): string
    {
        $key = is_string($value) ? $value : '';
        if ($key === '') {
            return 'Unspecified';
        }

        return self::historyCategories()[$key] ?? Str::headline($key);
    }

    private static function contextFilled(AuditLog $record, string $key): bool
    {
        $value = Arr::get($record->context, $key);

        if (is_array($value)) {
            return ! empty(array_filter($value, fn ($item): bool => trim((string) $item) !== ''));
        }

        return filled($value);
    }

    private static function formatList(mixed $value): string
    {
        if (! is_array($value)) {
            return 'n/a';
        }

        $items = array_values(array_filter(array_map(function ($item): string {
            return trim((string) $item);
        }, $value), fn (string $item): bool => $item !== ''));

        return $items === [] ? 'n/a' : implode(', ', $items);
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnifiedHistoryResource\Pages;
use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
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

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Unified History';

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
            TextEntry::make('created_at')->label('Time')->dateTime(),
            TextEntry::make('context.category')
                ->label('Category')
                ->badge()
                ->formatStateUsing(fn ($state): string => self::formatCategory($state)),
            TextEntry::make('context.scope')
                ->label('Scope')
                ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'scope')))
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'scope')),
            TextEntry::make('context.title')
                ->label('Title')
                ->wrap()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'title')),
            TextEntry::make('context.summary')
                ->label('Summary')
                ->wrap()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'summary')),
            TextEntry::make('context.details')
                ->label('Details')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'details')),
            TextEntry::make('context.findings')
                ->label('Findings')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'findings')),
            TextEntry::make('context.recommendations')
                ->label('Recommendations')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'recommendations')),
            TextEntry::make('context.mitigations')
                ->label('Mitigations')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'mitigations')),
            TextEntry::make('context.decisions')
                ->label('Decisions')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'decisions')),
            TextEntry::make('context.configuration_changes')
                ->label('Configuration Changes')
                ->wrap()
                ->columnSpanFull()
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'configuration_changes')),
            TextEntry::make('context.tags')
                ->label('Tags')
                ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'tags')))
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'tags')),
            TextEntry::make('context.references')
                ->label('References')
                ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'references')))
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'references')),
            TextEntry::make('context.related_request_id')
                ->label('Related Request ID')
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'related_request_id')),
            TextEntry::make('context.related_audit_id')
                ->label('Related Audit Log ID')
                ->visible(fn (AuditLog $record): bool => self::contextFilled($record, 'related_audit_id')),
            TextEntry::make('user.email')
                ->label('Actor')
                ->getStateUsing(fn (AuditLog $record): string => $record->user?->email ?? 'System'),
            TextEntry::make('request_id')->label('Request ID'),
            TextEntry::make('action')->label('Action')->badge(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('context.category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatCategory($state)),
                TextColumn::make('context.title')
                    ->label('Title')
                    ->wrap(),
                TextColumn::make('context.summary')
                    ->label('Summary')
                    ->limit(120)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('context.scope')
                    ->label('Scope')
                    ->formatStateUsing(fn (AuditLog $record): string => self::formatList(Arr::get($record->context, 'scope')))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')
                    ->label('Actor')
                    ->getStateUsing(fn (AuditLog $record): string => $record->user?->email ?? 'System'),
                TextColumn::make('request_id')
                    ->label('Request ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('action')
                    ->label('Action')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(self::historyCategories())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->where('context->category', $value);
                    }),
                SelectFilter::make('scope')
                    ->label('Scope')
                    ->options(self::historyScopes())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->whereJsonContains('context->scope', $value);
                    }),
            ])
            ->emptyStateHeading('Belum ada histori terpadu')
            ->emptyStateDescription('Kegiatan audit/hardening akan tampil setelah ada entri yang memenuhi kategori keamanan.')
            ->emptyStateActions([
                Action::make('refresh')
                    ->label('Segarkan')
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->authorize(fn (): bool => auth()->user()?->can('view_unified_history') ?? false),
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
        return auth()->user()?->can('view_any_unified_history') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_unified_history') ?? false;
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

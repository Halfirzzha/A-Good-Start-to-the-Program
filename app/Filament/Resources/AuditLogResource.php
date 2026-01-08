<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\AuthHelper;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Time')->dateTime(),
            TextEntry::make('action')->badge(),
            TextEntry::make('user.email')->label('User'),
            TextEntry::make('auditable_type')->label('Entity Type'),
            TextEntry::make('auditable_id')->label('Entity ID'),
            TextEntry::make('ip_address')->label('IP'),
            TextEntry::make('user_agent')->label('User Agent'),
            TextEntry::make('method')->label('Method'),
            TextEntry::make('route')->label('Route'),
            TextEntry::make('url')->label('URL'),
            TextEntry::make('status_code')->label('Status'),
            TextEntry::make('request_id')->label('Request ID'),
            TextEntry::make('session_id')->label('Session ID'),
            TextEntry::make('duration_ms')->label('Duration (ms)'),
            KeyValueEntry::make('old_values')
                ->label('Old Values')
                ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->old_values)),
            KeyValueEntry::make('new_values')
                ->label('New Values')
                ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->new_values)),
            KeyValueEntry::make('context')
                ->label('Context')
                ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->context)),
            TextEntry::make('hash')->label('Hash'),
            TextEntry::make('previous_hash')->label('Previous Hash'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->sortable()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->created_at?->diffForHumans() ?? '—')
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->format('d M Y, H:i:s T')),
                TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->searchable()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'created') => 'success',
                        str_contains($state, 'updated') => 'warning',
                        str_contains($state, 'deleted') => 'danger',
                        str_contains($state, 'login') => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('user')
                    ->label('User')
                    ->getStateUsing(function (AuditLog $record): string {
                        $user = $record->user;
                        if (! $user) {
                            return 'System';
                        }
                        return $user->name ?: $user->email ?: $user->username ?: 'User';
                    })
                    ->description(function (AuditLog $record): ?string {
                        $user = $record->user;
                        if (! $user) {
                            return null;
                        }
                        $role = $user->role ?: $user->getRoleNames()->first();
                        return $role ? 'Role: '.$role : null;
                    })
                    ->searchable(),
                TextColumn::make('target')
                    ->label('Target')
                    ->badge()
                    ->getStateUsing(fn (AuditLog $record): string => $record->auditable_type ? class_basename($record->auditable_type) : '—')
                    ->description(fn (AuditLog $record): ?string => $record->auditable_id ? 'ID: '.$record->auditable_id : null),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->pluck('action', 'action')
                        ->sort()
                        ->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('auditable_type')
                    ->label('Entity Type')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->pluck('auditable_type')
                        ->filter()
                    ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                    ->sort()
                    ->all())
                    ->searchable(),
            ])
            ->searchable()
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->recordActions([
                ViewAction::make()
                    ->authorize('view')
                    ->visible(fn (AuditLog $record): bool => AuthHelper::user()?->can('view', $record) ?? false),
            ])
            ->emptyStateHeading('Log audit belum tersedia')
            ->emptyStateDescription('Audit log akan muncul secara real-time saat aksi tercatat.')
            ->emptyStateActions([
                Action::make('refresh')
                    ->label('Segarkan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
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

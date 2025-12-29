<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
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
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label('Entity')
                    ->searchable(),
                TextColumn::make('auditable_id')
                    ->label('Entity ID')
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable(),
                TextColumn::make('method')
                    ->label('Method')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status_code')
                    ->label('Status')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hash')
                    ->label('Hash')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('previous_hash')
                    ->label('Previous Hash')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->authorize('view')
                    ->visible(fn (AuditLog $record): bool => auth()->user()?->can('view', $record) ?? false),
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

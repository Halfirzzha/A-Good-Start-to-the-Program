<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceHistoryResource\Pages;
use App\Models\AuditLog;
use App\Support\AuthHelper;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaintenanceHistoryResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static string | \UnitEnum | null $navigationGroup = 'Maintenance';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->sortable()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->created_at?->diffForHumans() ?? '—')
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->format('d M Y, H:i:s T')),
                TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::actionLabel($state)),
                TextColumn::make('actor')
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
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $sub) use ($search): void {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('target')
                    ->label('Target')
                    ->badge()
                    ->getStateUsing(function (AuditLog $record): string {
                        return str_contains($record->action, 'token') ? 'MaintenanceToken' : 'Maintenance';
                    }),
                TextColumn::make('changes')
                    ->label('Perubahan')
                    ->getStateUsing(fn (AuditLog $record): string => self::formatChanges($record))
                    ->html()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Aksi')
                    ->options(collect(self::actionsFilter())
                        ->mapWithKeys(fn (string $action): array => [$action => self::actionLabel($action)])
                        ->all()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('action', self::actionsFilter()))
            ->searchable()
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->emptyStateHeading('Belum ada riwayat maintenance')
            ->emptyStateDescription('Riwayat akan muncul ketika jadwal atau status maintenance berubah.')
            ->emptyStateActions([
                Action::make('refresh')
                    ->label('Segarkan')
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->toolbarActions([]);
    }

    public static function canViewAny(): bool
    {
        $user = AuthHelper::user();

        return $user && $user->isDeveloper();
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
            'index' => Pages\ListMaintenanceHistories::route('/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function actionsFilter(): array
    {
        return [
            'maintenance_settings_updated',
            'maintenance_schedule_updated',
            'maintenance_enabled',
            'maintenance_disabled',
            'maintenance_auto_enabled',
            'maintenance_auto_disabled',
            'maintenance_note_updated',
            'maintenance_token_created',
            'maintenance_token_rotated',
            'maintenance_token_revoked',
        ];
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'maintenance_settings_updated' => 'Updated Settings',
            'maintenance_schedule_updated' => 'Updated Schedule',
            'maintenance_enabled' => 'Enabled Maintenance',
            'maintenance_disabled' => 'Disabled Maintenance',
            'maintenance_auto_enabled' => 'Auto Enabled',
            'maintenance_auto_disabled' => 'Auto Disabled',
            'maintenance_note_updated' => 'Updated Operator Note',
            'maintenance_token_created' => 'Created Bypass Token',
            'maintenance_token_rotated' => 'Rotated Bypass Token',
            'maintenance_token_revoked' => 'Revoked Bypass Token',
            default => $action,
        };
    }

    private static function formatChanges(AuditLog $record): string
    {
        $changes = $record->context['changes'] ?? null;
        if (! is_array($changes) || $changes === []) {
            return 'Tidak ada perubahan terdeteksi.';
        }

        $lines = [];
        foreach ($changes as $change) {
            $field = $change['field'] ?? 'Field';
            $from = $change['from'] ?? 'null';
            $to = $change['to'] ?? 'null';
            $lines[] = e($field) . ': ' . e($from) . ' → ' . e($to);
        }

        return implode('<br>', $lines);
    }
}

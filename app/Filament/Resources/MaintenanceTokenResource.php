<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceTokenResource\Pages;
use App\Models\MaintenanceToken;
use App\Support\MaintenanceTokenService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class MaintenanceTokenResource extends Resource
{
    protected static ?string $model = MaintenanceToken::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-key';

    protected static string | \UnitEnum | null $navigationGroup = 'Maintenance';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Token')
                    ->searchable()
                    ->placeholder('Tidak ada'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (MaintenanceToken $record): string => $record->isActive() ? 'Active' : 'Revoked/Expired')
                    ->color(fn (MaintenanceToken $record): string => $record->isActive() ? 'success' : 'gray'),
                TextColumn::make('last_used_at')
                    ->label('Terakhir Digunakan')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Belum dipakai'),
                TextColumn::make('expires_at')
                    ->label('Kedaluwarsa')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Tidak ada'),
                TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('System'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('create_token')
                    ->label('Buat Token')
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('name')
                            ->label('Nama Token')
                            ->maxLength(100),
                        DateTimePicker::make('expires_at')
                            ->label('Kedaluwarsa (UTC)')
                            ->timezone('UTC')
                            ->seconds(false)
                            ->helperText('Opsional. Kosongkan jika token tidak kedaluwarsa.'),
                    ])
                    ->action(function (array $data): void {
                        $result = MaintenanceTokenService::create($data, auth()->id());
                        $plain = $result['token'];

                        Notification::make()
                            ->title('Token dibuat')
                            ->body(new HtmlString('Simpan token ini sekarang: <strong>' . e($plain) . '</strong>'))
                            ->success()
                            ->persistent()
                            ->send();
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
            ])
            ->actions([
                Action::make('rotate')
                    ->label('Rotate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (MaintenanceToken $record): void {
                        $plain = MaintenanceTokenService::rotate($record, auth()->id());
                        Notification::make()
                            ->title('Token diganti')
                            ->body(new HtmlString('Token baru: <strong>' . e($plain) . '</strong>'))
                            ->success()
                            ->persistent()
                            ->send();
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (MaintenanceToken $record): void {
                        MaintenanceTokenService::revoke($record, auth()->id());
                        Notification::make()
                            ->title('Token dicabut')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (): bool => self::canManageTokens()),
                DeleteAction::make()
                    ->label('Hapus Permanen')
                    ->visible(fn (): bool => self::canManageTokens()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select(['*']))
            ->emptyStateHeading('Belum ada token maintenance')
            ->emptyStateDescription('Buat token untuk memberikan akses sementara selama maintenance.')
            ->toolbarActions([]);
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
        $user = auth()->user();

        return $user
            && method_exists($user, 'isDeveloper')
            && $user->isDeveloper()
            && $user->can('execute_maintenance_bypass_token');
    }
}

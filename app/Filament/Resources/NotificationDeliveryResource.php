<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDeliveryResource\Pages;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\AuthHelper;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('created_at')
                            ->label('Waktu')
                            ->dateTime('d M Y, H:i:s T')
                            ->sortable()
                            ->description(fn (NotificationDelivery $record): ?string => $record->created_at?->diffForHumans()),
                        TextColumn::make('notification_type')
                            ->label('Tipe')
                            ->formatStateUsing(fn (string $state): string => class_basename($state))
                            ->searchable()
                            ->limit(32)
                            ->tooltip(fn (NotificationDelivery $record): ?string => class_basename($record->notification_type)),
                    ]),
                    Stack::make([
                        TextColumn::make('channel')
                            ->label('Channel')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? strtoupper($state) : 'N/A'),
                        TextColumn::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'sent' ? 'success' : ($state === 'failed' ? 'danger' : 'gray')),
                    ])->space(1),
                ])->from('md'),
                IconColumn::make('is_sent')
                    ->label('Sent')
                    ->boolean()
                    ->getStateUsing(fn (NotificationDelivery $record): bool => $record->status === 'sent')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recipient')
                    ->label('Penerima')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('notifiable')
                    ->label('User')
                    ->getStateUsing(function (NotificationDelivery $record): string {
                        if ($record->notifiable_type === User::class && $record->notifiable) {
                            $user = $record->notifiable;
                            $identity = $user->email ?: $user->username ?: $user->name;
                            return $identity ?: 'User';
                        }

                        return $record->notifiable_id ? (string) $record->notifiable_id : 'System';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph(
                            'notifiable',
                            [User::class],
                            function (Builder $sub) use ($search): void {
                                $sub->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('username', 'like', "%{$search}%");
                            }
                        );
                    }),
                TextColumn::make('device_type')
                    ->label('Device')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'mobile' ? 'info' : ($state === 'tablet' ? 'warning' : 'gray'))
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_mobile')
                    ->label('Mobile')
                    ->boolean()
                    ->getStateUsing(fn (NotificationDelivery $record): bool => $record->device_type === 'mobile')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('summary')
                    ->label('Ringkasan')
                    ->formatStateUsing(fn (?string $state): string => $state ? Str::limit($state, 60) : '—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Channel')
                    ->options([
                        'mail' => 'Email',
                        'database' => 'In-App',
                        'telegram' => 'Telegram',
                        'sms' => 'SMS',
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                    ]),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('notifiable'))
            ->emptyStateHeading('Belum ada notifikasi tercatat')
            ->emptyStateDescription('Catatan pengiriman notifikasi akan muncul setelah notifikasi dikirim.')
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
            'index' => Pages\ListNotificationDeliveries::route('/'),
        ];
    }
}

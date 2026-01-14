<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserNotificationResource\Pages;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AuthHelper;
use App\Support\NotificationCenterService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class UserNotificationResource extends Resource
{
    protected static ?string $model = UserNotification::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 0;

    public static function getModelLabel(): string
    {
        return __('notifications.ui.inbox.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.ui.inbox.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.monitoring');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('delivered_at', 'desc')
            ->striped()
            ->columns([
                IconColumn::make('is_read')
                    ->label(__('notifications.ui.inbox.read'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-bell-alert')
                    ->trueColor('success')
                    ->falseColor('warning'),
                TextColumn::make('notification.title')
                    ->label(__('notifications.ui.inbox.title'))
                    ->searchable()
                    ->description(function (UserNotification $record): ?string {
                        $message = $record->notification?->message;
                        if (! is_string($message) || trim($message) === '') {
                            return null;
                        }

                        return Str::limit(strip_tags($message), 120);
                    })
                    ->wrap(),
                TextColumn::make('notification.category')
                    ->label(__('notifications.ui.inbox.category'))
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '—';
                        }

                        $options = NotificationCenterService::categoryOptions();

                        return $options[$state] ?? ucfirst($state);
                    }),
                TextColumn::make('notification.priority')
                    ->label(__('notifications.ui.inbox.priority'))
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '—';
                        }

                        $options = NotificationCenterService::priorityOptions();

                        return $options[$state] ?? ucfirst($state);
                    }),
                TextColumn::make('delivered_at')
                    ->label(__('notifications.ui.inbox.delivered'))
                    ->formatStateUsing(fn (UserNotification $record): string => $record->delivered_at?->diffForHumans() ?? '—')
                    ->description(fn (UserNotification $record): ?string => $record->delivered_at?->format('d M Y, H:i:s T')),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('notifications.ui.inbox.category'))
                    ->options(NotificationCenterService::categoryOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->whereHas('notification', function (Builder $sub) use ($value): void {
                            $sub->where('category', $value);
                        });
                    }),
                SelectFilter::make('priority')
                    ->label(__('notifications.ui.inbox.priority'))
                    ->options(NotificationCenterService::priorityOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->whereHas('notification', function (Builder $sub) use ($value): void {
                            $sub->where('priority', $value);
                        });
                    }),
                TernaryFilter::make('is_read')
                    ->label(__('notifications.ui.inbox.read_status'))
                    ->trueLabel(__('notifications.ui.filters.read_read'))
                    ->falseLabel(__('notifications.ui.filters.read_unread')),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->modifyQueryUsing(function (Builder $query): Builder {
                $userId = AuthHelper::id();

                return $query
                    ->where('user_id', $userId)
                    ->with('notification');
            })
            ->recordActions([
                Action::make('mark_read')
                    ->label(__('notifications.ui.inbox.mark_read'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (): bool => self::canManageInbox())
                    ->visible(fn (UserNotification $record): bool => ! $record->is_read)
                    ->action(function (UserNotification $record): void {
                        self::markNotificationRead($record, true);
                    }),
                Action::make('mark_unread')
                    ->label(__('notifications.ui.inbox.mark_unread'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (): bool => self::canManageInbox())
                    ->visible(fn (UserNotification $record): bool => $record->is_read)
                    ->action(function (UserNotification $record): void {
                        self::markNotificationRead($record, false);
                    }),
            ])
            ->toolbarActions([
                Action::make('mark_all_read')
                    ->label(__('notifications.ui.inbox.mark_all_read'))
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (): bool => self::canManageInbox())
                    ->action(fn () => self::markAll(true)),
                Action::make('mark_all_unread')
                    ->label(__('notifications.ui.inbox.mark_all_unread'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (): bool => self::canManageInbox())
                    ->action(fn () => self::markAll(false)),
            ])
            ->emptyStateHeading(__('notifications.ui.inbox.empty_heading'))
            ->emptyStateDescription(__('notifications.ui.inbox.empty_description'));
    }

    public static function canViewAny(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        return $user->can('view_any_user_notification')
            || $user->can('view_user_notification')
            || $user->can('view_any_user_notifications')
            || $user->can('view_user_notifications');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
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
            'index' => Pages\ListUserNotifications::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $userId = AuthHelper::id();
        if (! $userId) {
            return null;
        }

        $count = UserNotification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function markNotificationRead(UserNotification $record, bool $read): void
    {
        if (! self::canManageInbox()) {
            abort(403);
        }

        $record->forceFill([
            'is_read' => $read,
            'read_at' => $read ? now() : null,
        ])->save();

        self::syncDatabaseNotification($record->user_id, $record->notification_id, $read);
    }

    private static function canManageInbox(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        return $user->can('update_user_notification')
            || $user->can('update_user_notifications')
            || $user->can('manage_user_notification')
            || $user->can('manage_user_notifications');
    }

    private static function markAll(bool $read): void
    {
        if (! self::canManageInbox()) {
            abort(403);
        }

        $userId = AuthHelper::id();
        if (! $userId) {
            return;
        }

        $timestamp = $read ? now() : null;

        UserNotification::query()
            ->where('user_id', $userId)
            ->update([
                'is_read' => $read,
                'read_at' => $timestamp,
            ]);

        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNotNull('data->notification_message_id')
            ->update([
                'read_at' => $timestamp,
            ]);
    }

    private static function syncDatabaseNotification(int $userId, int $notificationId, bool $read): void
    {
        $timestamp = $read ? now() : null;

        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('data->notification_message_id', $notificationId)
            ->update([
                'read_at' => $timestamp,
            ]);
    }
}

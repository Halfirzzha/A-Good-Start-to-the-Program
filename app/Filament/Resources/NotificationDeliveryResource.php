<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDeliveryResource\Pages;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\AuthHelper;
use App\Support\NotificationCenterService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('notifications.ui.delivery.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.ui.delivery.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.monitoring');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('notifications.ui.delivery.time'))
                    ->sortable()
                    ->formatStateUsing(fn (NotificationDelivery $record): string => $record->created_at?->diffForHumans() ?? '—')
                    ->description(fn (NotificationDelivery $record): ?string => $record->created_at?->format('d M Y, H:i:s T')),
                TextColumn::make('notification')
                    ->label(__('notifications.ui.delivery.notification'))
                    ->getStateUsing(function (NotificationDelivery $record): string {
                        $title = $record->notificationMessage?->title;
                        if (is_string($title) && $title !== '') {
                            return $title;
                        }

                        if (is_string($record->summary) && $record->summary !== '') {
                            return $record->summary;
                        }

                        return $record->notification_type
                            ? class_basename($record->notification_type)
                            : __('notifications.ui.common.system');
                    })
                    ->description(function (NotificationDelivery $record): ?string {
                        $category = $record->notificationMessage?->category;
                        if (is_string($category) && $category !== '') {
                            $options = NotificationCenterService::categoryOptions();
                            $label = $options[$category] ?? $category;

                            return strtoupper($label);
                        }

                        return $record->notification_type
                            ? class_basename($record->notification_type)
                            : null;
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $builder) use ($search): void {
                            $builder->where('summary', 'like', "%{$search}%")
                                ->orWhere('recipient', 'like', "%{$search}%")
                                ->orWhereHas('notificationMessage', function (Builder $messageQuery) use ($search): void {
                                    $messageQuery->where('title', 'like', "%{$search}%")
                                        ->orWhere('category', 'like', "%{$search}%");
                                });
                        });
                    }),
                TextColumn::make('channel')
                    ->label(__('notifications.ui.delivery.channel'))
                    ->badge()
                    ->getStateUsing(fn (NotificationDelivery $record): string => self::channelLabel($record->channel))
                    ->color(fn (): string => 'primary')
                    ->description(function (NotificationDelivery $record): ?string {
                        if (is_string($record->recipient) && $record->recipient !== '') {
                            return Str::limit($record->recipient, 60);
                        }

                        return null;
                    }),
                TextColumn::make('status')
                    ->label(__('notifications.ui.delivery.status'))
                    ->badge()
                    ->getStateUsing(fn (NotificationDelivery $record): string => self::statusLabel($record->status))
                    ->color(fn (NotificationDelivery $record): string => self::statusColor($record->status))
                    ->description(function (NotificationDelivery $record): ?string {
                        if ($record->attempts && $record->attempts > 0) {
                            return __('notifications.ui.common.attempt', ['count' => $record->attempts]);
                        }

                        return null;
                    }),
                TextColumn::make('notifiable')
                    ->label(__('notifications.ui.delivery.user'))
                    ->getStateUsing(function (NotificationDelivery $record): string {
                        if ($record->notifiable_type === User::class && $record->notifiable) {
                            $user = $record->notifiable;
                            $identity = $user->email ?: $user->username ?: $user->name;
                            return $identity ?: __('notifications.ui.delivery.user');
                        }

                        return $record->notifiable_id ? (string) $record->notifiable_id : __('notifications.ui.common.system');
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
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label(__('notifications.ui.delivery.channel'))
                    ->options([
                        'inapp' => __('notifications.ui.channels.inapp'),
                        'email' => __('notifications.ui.channels.email'),
                        'mail' => __('notifications.ui.channels.email_legacy'),
                        'database' => __('notifications.ui.channels.database_legacy'),
                        'telegram' => __('notifications.ui.channels.telegram'),
                        'sms' => __('notifications.ui.channels.sms'),
                    ]),
                SelectFilter::make('status')
                    ->label(__('notifications.ui.delivery.status'))
                    ->options([
                        'queued' => __('notifications.ui.delivery.status_labels.queued'),
                        'sent' => __('notifications.ui.delivery.status_labels.sent'),
                        'failed' => __('notifications.ui.delivery.status_labels.failed'),
                        'skipped' => __('notifications.ui.delivery.status_labels.skipped'),
                    ]),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->searchable()
            ->persistFiltersInSession()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['notifiable', 'notificationMessage']))
            ->emptyStateHeading(__('notifications.ui.delivery.empty_heading'))
            ->emptyStateDescription(__('notifications.ui.delivery.empty_description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('notifications.ui.delivery.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('notifications.ui.delivery.actions.view'))
                    ->authorize('view')
                    ->visible(fn (NotificationDelivery $record): bool => self::canView($record)),
            ])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('notifications.ui.delivery.sections.summary'))
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('notifications.ui.delivery.time'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => $record->created_at?->format('d M Y, H:i:s T') ?? '—')
                        ->helperText(fn (NotificationDelivery $record): string => $record->created_at?->diffForHumans() ?? '—'),
                    TextEntry::make('channel')
                        ->label(__('notifications.ui.delivery.channel'))
                        ->badge()
                        ->formatStateUsing(fn (NotificationDelivery $record): string => self::channelLabel($record->channel)),
                    TextEntry::make('status')
                        ->label(__('notifications.ui.delivery.status'))
                        ->badge()
                        ->formatStateUsing(fn (NotificationDelivery $record): string => self::statusLabel($record->status)),
                    TextEntry::make('attempts')
                        ->label(__('notifications.ui.delivery.attempts'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => (string) ($record->attempts ?? 0)),
                ])
                ->columns(2),
            Section::make(__('notifications.ui.delivery.sections.target'))
                ->schema([
                    TextEntry::make('notificationMessage.title')
                        ->label(__('notifications.ui.delivery.notification'))
                        ->visible(fn (NotificationDelivery $record): bool => filled($record->notificationMessage?->title)),
                    TextEntry::make('summary')
                        ->label(__('notifications.ui.delivery.summary'))
                        ->visible(fn (NotificationDelivery $record): bool => filled($record->summary)),
                    TextEntry::make('recipient')
                        ->label(__('notifications.ui.delivery.recipient'))
                        ->copyable()
                        ->visible(fn (NotificationDelivery $record): bool => filled($record->recipient)),
                    TextEntry::make('notification_type')
                        ->label(__('notifications.ui.delivery.type'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => $record->notification_type ? class_basename($record->notification_type) : __('notifications.ui.common.system')),
                ])
                ->columns(2),
            Section::make(__('notifications.ui.delivery.sections.request'))
                ->schema([
                    TextEntry::make('ip_address')->label(__('notifications.ui.delivery.request.ip')),
                    TextEntry::make('user_agent')->label(__('notifications.ui.delivery.request.user_agent'))->wrap(),
                    TextEntry::make('request_id')->label(__('notifications.ui.delivery.request.request_id'))->copyable(),
                    TextEntry::make('idempotency_key')->label(__('notifications.ui.delivery.request.idempotency_key'))->copyable(),
                    TextEntry::make('queued_at')
                        ->label(__('notifications.ui.delivery.request.queued_at'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => self::formatTimestamp($record->queued_at)),
                    TextEntry::make('sent_at')
                        ->label(__('notifications.ui.delivery.request.sent_at'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => self::formatTimestamp($record->sent_at)),
                    TextEntry::make('failed_at')
                        ->label(__('notifications.ui.delivery.request.failed_at'))
                        ->formatStateUsing(fn (NotificationDelivery $record): string => self::formatTimestamp($record->failed_at)),
                    TextEntry::make('error_message')
                        ->label(__('notifications.ui.delivery.request.error'))
                        ->visible(fn (NotificationDelivery $record): bool => filled($record->error_message)),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make(__('notifications.ui.delivery.sections.payload'))
                ->schema([
                    KeyValueEntry::make('data')
                        ->label(__('notifications.ui.delivery.payload.data'))
                        ->getStateUsing(fn (NotificationDelivery $record): array => self::normalizeKeyValue($record->data)),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ]);
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

        return $user->can('view_any_notification_delivery')
            || $user->can('view_notification_delivery')
            || $user->can('view_any_notification_deliveries')
            || $user->can('view_notification_deliveries');
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
            'index' => Pages\ListNotificationDeliveries::route('/'),
        ];
    }

    private static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'inapp', 'database' => __('notifications.ui.channels.inapp'),
            'email', 'mail' => __('notifications.ui.channels.email'),
            'telegram' => __('notifications.ui.channels.telegram'),
            'sms' => __('notifications.ui.channels.sms'),
            default => $channel ? strtoupper($channel) : __('notifications.ui.common.unknown'),
        };
    }

    private static function statusLabel(?string $status): string
    {
        $key = $status ? strtolower($status) : 'unknown';

        $label = __('notifications.ui.delivery.status_labels.'.$key);

        return $label === 'notifications.ui.delivery.status_labels.'.$key
            ? strtoupper($status ?? __('notifications.ui.common.unknown'))
            : $label;
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            'sent' => 'success',
            'failed' => 'danger',
            'queued' => 'warning',
            'skipped' => 'gray',
            default => 'secondary',
        };
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

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function formatTimestamp(mixed $value): string
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->format('d M Y, H:i:s T');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y, H:i:s T');
        }

        return '—';
    }
}

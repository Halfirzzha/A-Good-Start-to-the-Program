<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationMessageResource\Pages;
use App\Models\NotificationMessage;
use App\Models\NotificationTarget;
use App\Support\AuthHelper;
use App\Support\NotificationCenterService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Spatie\Permission\Models\Role;

class NotificationMessageResource extends Resource
{
    protected static ?string $model = NotificationMessage::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('notifications.ui.center.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.ui.center.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.monitoring');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('notifications.ui.center.sections.content'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('notifications.ui.center.fields.title'))
                        ->required()
                        ->maxLength(200),
                    RichEditor::make('message')
                        ->label(__('notifications.ui.center.fields.message'))
                        ->toolbarButtons([
                            'bold',
                            'italic',
                            'bulletList',
                            'orderedList',
                            'link',
                        ])
                        ->required()
                        ->columnSpanFull(),
                    Select::make('category')
                        ->label(__('notifications.ui.center.fields.category'))
                        ->options(NotificationCenterService::categoryOptions())
                        ->native(false)
                        ->required(),
                    Select::make('priority')
                        ->label(__('notifications.ui.center.fields.priority'))
                        ->options(NotificationCenterService::priorityOptions())
                        ->native(false)
                        ->required()
                        ->default('normal'),
                ])
                ->columns(2),
            Section::make(__('notifications.ui.center.sections.targeting'))
                ->schema([
                    Toggle::make('target_all')
                        ->label(__('notifications.ui.center.fields.target_all'))
                        ->live()
                        ->helperText(__('notifications.ui.center.fields.target_all_help'))
                        ->afterStateUpdated(function (Get $get, callable $set): void {
                            if ($get('target_all')) {
                                $set('target_roles', []);
                            }
                        }),
                    Select::make('target_roles')
                        ->label(__('notifications.ui.center.fields.target_roles'))
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => self::roleOptions())
                        ->visible(fn (Get $get): bool => ! (bool) $get('target_all'))
                        ->required(fn (Get $get): bool => ! (bool) $get('target_all')),
                ])
                ->columns(2),
            Section::make(__('notifications.ui.center.sections.channels'))
                ->schema([
                    CheckboxList::make('channels')
                        ->label(__('notifications.ui.center.fields.channels'))
                        ->options(NotificationCenterService::channelOptions())
                        ->columns(2)
                        ->required()
                        ->default(['inapp']),
                ]),
            Section::make(__('notifications.ui.center.sections.schedule'))
                ->schema([
                    DateTimePicker::make('scheduled_at')
                        ->label(__('notifications.ui.center.fields.scheduled_at'))
                        ->seconds(false),
                    DateTimePicker::make('expires_at')
                        ->label(__('notifications.ui.center.fields.expires_at'))
                        ->seconds(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('notifications.ui.center.fields.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('notifications.ui.center.fields.category'))
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '—';
                        }

                        $options = NotificationCenterService::categoryOptions();

                        return $options[$state] ?? ucfirst($state);
                    }),
                TextColumn::make('priority')
                    ->label(__('notifications.ui.center.fields.priority'))
                    ->badge()
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '—';
                        }

                        $options = NotificationCenterService::priorityOptions();

                        return $options[$state] ?? ucfirst($state);
                    }),
                TextColumn::make('status')
                    ->label(__('notifications.ui.center.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                TextColumn::make('scheduled_at')
                    ->label(__('notifications.ui.center.fields.schedule'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label(__('notifications.ui.center.fields.sent'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_by')
                    ->label(__('notifications.ui.center.fields.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->striped()
            ->recordActions([
                Action::make('send_now')
                    ->label(__('notifications.ui.center.actions.send_now'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize('execute_notification_send')
                    ->visible(fn (NotificationMessage $record): bool => $record->status !== 'sent'
                        && (AuthHelper::user()?->can('execute_notification_send') ?? false))
                    ->action(function (NotificationMessage $record): void {
                        if (! AuthHelper::user()?->can('execute_notification_send')) {
                            abort(403);
                        }

                        if (self::isRateLimited('notification_send', 4, 60)) {
                            \Filament\Notifications\Notification::make()
                                ->title(__('notifications.ui.center.actions.too_many_title'))
                                ->body(__('notifications.ui.center.actions.too_many_body'))
                                ->warning()
                                ->send();
                            return;
                        }

                        NotificationCenterService::send($record);
                    }),
                Action::make('refresh')
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->iconButton()
                    ->tooltip(__('notifications.ui.center.actions.refresh'))
                    ->action(fn () => redirect()->to(request()->fullUrl())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationMessages::route('/'),
            'create' => Pages\CreateNotificationMessage::route('/create'),
            'edit' => Pages\EditNotificationMessage::route('/{record}/edit'),
        ];
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

        return $user->can('view_any_notification_message')
            || $user->can('view_notification_message')
            || $user->can('view_any_notification_messages')
            || $user->can('view_notification_messages');
    }

    public static function canView(Model $record): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('view', $record);
    }

    public static function canCreate(): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('create_notification_message');
    }

    public static function canEdit(Model $record): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        $user = AuthHelper::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('delete', $record);
    }

    private static function statusLabel(?string $status): string
    {
        $key = $status ? strtolower($status) : 'unknown';

        $label = __('notifications.ui.center.status_labels.'.$key);

        return $label === 'notifications.ui.center.status_labels.'.$key
            ? strtoupper($status ?? __('notifications.ui.common.unknown'))
            : $label;
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        if (SchemaFacade::hasTable('roles')) {
            return Role::query()->orderBy('name')->pluck('name', 'name')->all();
        }

        return array_keys(config('security.role_hierarchy', []));
    }

    public static function syncTargets(NotificationMessage $record, bool $targetAll, array $roles): void
    {
        $record->targets()->delete();

        if ($targetAll) {
            $record->targets()->create([
                'target_type' => 'all',
                'target_value' => null,
            ]);
            return;
        }

        foreach ($roles as $role) {
            $record->targets()->create([
                'target_type' => 'role',
                'target_value' => $role,
            ]);
        }
    }

    public static function syncChannels(NotificationMessage $record, array $channels): void
    {
        $record->channels()->delete();

        foreach ($channels as $channel) {
            $record->channels()->create([
                'channel' => $channel,
                'enabled' => true,
                'max_attempts' => 3,
                'retry_after_seconds' => 60,
                'created_by' => AuthHelper::id(),
            ]);
        }
    }

    private static function isRateLimited(string $key, int $maxAttempts, int $seconds): bool
    {
        $userId = AuthHelper::id() ?: 'guest';
        $cacheKey = "rate:notification_center:{$key}:{$userId}";

        $attempts = Cache::increment($cacheKey);
        if ($attempts === 1) {
            Cache::put($cacheKey, 1, now()->addSeconds($seconds));
        }

        return $attempts > $maxAttempts;
    }
}

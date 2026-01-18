<?php

namespace App\Filament\Resources;

use App\Enums\AccountStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\PasswordRules;
use App\Support\SystemSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    private const AVATAR_DELETE_QUEUE = 'user_avatar_delete_queue';

    /** @var array<string, bool> Static permission cache per request */
    private static array $permissionCache = [];

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.security');
    }

    public static function form(Schema $schema): Schema
    {
        $isViewer = (AuthHelper::user()?->hasRole('viewer') ?? false)
            || (AuthHelper::user()?->role === 'viewer');
        [$disk, $fallbackDisk] = self::resolveAvatarUploadDisks();
        $countryOptions = self::asianCountryDialCodes();

        return $schema->components([
            \Filament\Schemas\Components\Tabs::make(__('ui.users.tabs.user'))
                ->persistTabInQueryString()
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.users.tabs.main'))
                        ->icon('heroicon-o-user')
                        ->visible(fn (?User $record): bool => self::canViewAvatar($record) || self::canViewIdentity($record))
                        ->schema([
                            Section::make(__('ui.users.sections.avatar'))
                                ->description(__('ui.users.descriptions.avatar'))
                                ->visible(fn (?User $record): bool => self::canViewAvatar($record))
                                ->schema([
                                    FileUpload::make('avatar')
                                        ->label(__('ui.users.fields.avatar'))
                                        ->disk($disk)
                                        ->directory('avatars')
                                        ->avatar()
                                        ->imageEditor()
                                        ->imagePreviewHeight('44')
                                        ->imageResizeTargetWidth('512')
                                        ->imageResizeTargetHeight('512')
                                        ->maxSize(512)
                                        ->visibility('public')
                                        ->helperText(__('ui.users.helpers.avatar'))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageAvatar($record))
                                        ->getUploadedFileUsing(function (string $file, string|array|null $storedFileNames): ?array {
                                            $parsed = self::parseAvatarState($file);
                                            if ($parsed['url']) {
                                                $basename = basename((string) parse_url($parsed['url'], PHP_URL_PATH));

                                                return [
                                                    'name' => $basename ?: 'avatar',
                                                    'size' => 0,
                                                    'type' => null,
                                                    'url' => $parsed['url'],
                                                ];
                                            }

                                            $path = self::normalizeAvatarPath($parsed['path'] ?? $file);
                                            $diskName = self::resolveAvatarDiskForPath($path, $parsed['disk']);

                                            try {
                                                if (! self::isPublicDisk($diskName)) {
                                                    return null;
                                                }

                                                $storage = Storage::disk($diskName);
                                                if (! $storage->exists($path)) {
                                                    return null;
                                                }

                                                /** @var \Illuminate\Contracts\Filesystem\Filesystem $storage */
                                                $mimeType = method_exists($storage, 'mimeType') ? $storage->mimeType($path) : null;
                                                $url = method_exists($storage, 'url') ? $storage->url($path) : null;

                                                return [
                                                    'name' => is_array($storedFileNames) ? ($storedFileNames[$path] ?? basename($path)) : ($storedFileNames ?? basename($path)),
                                                    'size' => (int) $storage->size($path),
                                                    'type' => $mimeType,
                                                    'url' => $url,
                                                ];
                                            } catch (\Throwable) {
                                                return null;
                                            }
                                        })
                                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) use ($disk, $fallbackDisk): ?string {
                                            $directory = 'avatars';
                                            $filename = $file->hashName();
                                            $storeMethod = 'storePubliclyAs';

                                            try {
                                                return $file->{$storeMethod}($directory, $filename, $disk);
                                            } catch (\Throwable) {
                                                $fallback = $fallbackDisk ?: 'public';

                                                return $file->{$storeMethod}($directory, $filename, $fallback);
                                            }
                                        }),
                                ])
                                ->columns(1),
                            Section::make(__('ui.users.sections.identity'))
                                ->description(__('ui.users.descriptions.identity'))
                                ->visible(fn (?User $record): bool => self::canViewIdentity($record))
                                ->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->maxLength(255)
                                        ->disabled(fn (?User $record): bool => $isViewer
                                            || ! self::canManageIdentity($record)
                                            || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges())),
                                    TextInput::make('email')
                                        ->email()
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(ignoreRecord: true)
                                        ->disabled(fn (?User $record): bool => $isViewer
                                            || ! self::canManageIdentity($record)
                                            || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges())),
                                    TextInput::make('username')
                                        ->maxLength(50)
                                        ->unique(ignoreRecord: true)
                                        ->disabled(fn (string $operation, ?User $record): bool => $isViewer
                                            || $operation === 'create'
                                            || ! self::canManageIdentity($record)
                                            || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges()))
                                        ->helperText(__('ui.users.helpers.username')),
                                    TextInput::make('position')
                                        ->maxLength(100)
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageIdentity($record)),
                                    Select::make('role')
                                        ->label(__('ui.users.fields.role'))
                                        ->options(fn (?User $record): array => self::roleOptions($record))
                                        ->required(fn (): bool => self::canAssignRoles())
                                        ->native(false)
                                        ->searchable()
                                        ->default(fn (): ?string => self::defaultRoleName())
                                        ->disabled(fn (?User $record): bool => $isViewer
                                            || ! self::canAssignRoles($record)
                                            || ! self::canManageIdentity($record)
                                            || ($record?->isDeveloper() ?? false))
                                        ->helperText(fn (?User $record): ?string => $record?->isDeveloper() ? __('ui.users.helpers.role_immutable') : null),
                                    Select::make('phone_country_code')
                                        ->label(__('ui.users.fields.country_code'))
                                        ->options($countryOptions)
                                        ->default(fn (): string => self::detectCountryDialCode())
                                        ->native(false)
                                        ->searchable()
                                        ->required()
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageIdentity($record)),
                                    TextInput::make('phone_number')
                                        ->label(__('ui.users.fields.phone'))
                                        ->tel()
                                        ->numeric()
                                        ->rules(['nullable', 'regex:/^[0-9]{6,20}$/'])
                                        ->maxLength(20)
                                        ->helperText(__('ui.users.helpers.phone'))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageIdentity($record)),
                                ])
                                ->columns(2),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.users.tabs.security'))
                        ->icon('heroicon-o-shield-check')
                        ->visible(fn (?User $record): bool => self::canViewSecurity($record) || self::canViewAccessStatus($record))
                        ->schema([
                            Section::make(__('ui.users.sections.security'))
                                ->description(__('ui.users.descriptions.security'))
                                ->visible(fn (?User $record): bool => self::canViewSecurity($record))
                                ->schema([
                                    TextInput::make('password')
                                        ->label(__('ui.users.fields.password'))
                                        ->password()
                                        ->revealable()
                                        ->visible(fn (string $operation): bool => $operation === 'edit')
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageSecurity($record))
                                        ->rules(function (Get $get, ?User $record, string $operation): array {
                                            if ($operation !== 'edit' || blank($get('password'))) {
                                                return [];
                                            }

                                            return PasswordRules::build($record);
                                        })
                                        ->helperText(PasswordRules::requirements())
                                        ->dehydrated(fn ($state): bool => filled($state))
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, $state): void {
                                            if (blank($state)) {
                                                return;
                                            }

                                            $days = max(1, (int) config('security.password_expiry_days', 90));
                                            $set('password_expires_at', now()->addDays($days));
                                        }),
                                    TextInput::make('password_confirmation')
                                        ->label(__('ui.users.fields.password_confirmation'))
                                        ->password()
                                        ->dehydrated(false)
                                        ->same('password')
                                        ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password')))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageSecurity($record))
                                        ->required(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password'))),
                                    Toggle::make('must_change_password')
                                        ->label(__('ui.users.fields.require_password_change'))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageSecurity($record)),
                                    DateTimePicker::make('password_expires_at')
                                        ->label(__('ui.users.fields.password_expires_at'))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageSecurity($record))
                                        ->helperText(function (Get $get): string {
                                            $value = $get('password_expires_at');
                                            if (! $value) {
                                                return __('ui.users.helpers.password_expires_default');
                                            }

                                            try {
                                                $target = \Carbon\Carbon::parse($value);
                                            } catch (\Throwable) {
                                                return __('ui.users.helpers.password_expires_default');
                                            }

                                            $now = now();
                                            $seconds = $now->diffInSeconds($target, false);
                                            $days = (int) ceil(abs($seconds) / 86400);

                                            if ($seconds <= 0) {
                                                return $days === 0
                                                    ? __('ui.users.helpers.password_expires_today')
                                                    : __('ui.users.helpers.password_expires_past', ['days' => $days]);
                                            }

                                            return $days === 0
                                                ? __('ui.users.helpers.password_expires_today')
                                                : __('ui.users.helpers.password_expires_future', ['days' => $days]);
                                        }),
                                    Toggle::make('two_factor_enabled')
                                        ->label(__('ui.users.fields.two_factor_enabled'))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageSecurity($record))
                                        ->live()
                                        ->helperText(__('ui.users.helpers.two_factor_helper')),
                                    Select::make('two_factor_method')
                                        ->label(__('ui.users.fields.two_factor_method'))
                                        ->options([
                                            'app' => 'Authenticator App',
                                            'sms' => 'SMS',
                                            'email' => 'Email (Gmail)',
                                        ])
                                        ->native(false)
                                        ->live()
                                        ->disabled(fn (Get $get, ?User $record): bool => $isViewer
                                            || ! self::canManageSecurity($record)
                                            || ! $get('two_factor_enabled'))
                                        ->required(fn (Get $get): bool => (bool) $get('two_factor_enabled')),
                                    TextInput::make('security_stamp')
                                        ->label(__('ui.users.fields.security_stamp'))
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->helperText(__('ui.users.helpers.security_stamp')),
                                ])
                                ->columns(2),
                            Section::make(__('ui.users.sections.access'))
                                ->visible(fn (?User $record): bool => self::canViewAccessStatus($record))
                                ->schema([
                                    Select::make('account_status')
                                        ->options(AccountStatus::labels())
                                        ->required()
                                        ->native(false)
                                        ->default(AccountStatus::Active->value)
                                        ->disabled(fn (?User $record): bool => $isViewer
                                            || ! self::canManageAccessStatus($record)
                                            || ($record ? ! self::canManageUser($record) : false))
                                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                            $status = $state ?? $get('account_status');
                                            if (! in_array($status, [
                                                AccountStatus::Blocked->value,
                                                AccountStatus::Suspended->value,
                                                AccountStatus::Terminated->value,
                                            ], true)) {
                                                return;
                                            }

                                            if (filled($get('blocked_reason'))) {
                                                return;
                                            }

                                            $template = __('ui.users.templates.blocked_reason', [
                                                'status' => $status,
                                                'date' => now()->format('Y-m-d'),
                                            ]);
                                            $set('blocked_reason', $template);
                                        }),
                                    DateTimePicker::make('blocked_until')
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageAccessStatus($record)),
                                    RichEditor::make('blocked_reason')
                                        ->label(__('ui.users.fields.status_reason'))
                                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                                        ->required(fn (Get $get): bool => in_array($get('account_status'), [
                                            AccountStatus::Blocked->value,
                                            AccountStatus::Suspended->value,
                                            AccountStatus::Terminated->value,
                                        ], true))
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canManageAccessStatus($record))
                                        ->columnSpanFull(),
                                    TextInput::make('blocked_by')
                                        ->label(__('ui.users.fields.blocked_by'))
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->blocked_by)),
                                    DateTimePicker::make('locked_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->locked_at)),
                                    TextInput::make('failed_login_attempts')
                                        ->numeric()
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => (int) ($record?->failed_login_attempts ?? 0) > 0),
                                ])
                                ->columns(2),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.users.tabs.system_info'))
                        ->icon('heroicon-o-cog-6-tooth')
                        ->visible(fn (?User $record): bool => self::canViewSystemInfo($record))
                        ->schema([
                            Section::make(__('ui.users.sections.telemetry_audit'))
                                ->schema([
                                    DateTimePicker::make('first_login_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->first_login_at)),
                                    DateTimePicker::make('last_login_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_login_at)),
                                    TextInput::make('last_login_ip')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_login_ip)),
                                    DateTimePicker::make('last_failed_login_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_failed_login_at)),
                                    TextInput::make('last_failed_login_ip')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_failed_login_ip)),
                                    DateTimePicker::make('last_seen_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_seen_at)),
                                    TextInput::make('last_seen_ip')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->last_seen_ip)),
                                    DateTimePicker::make('password_changed_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->password_changed_at)),
                                    TextInput::make('password_changed_by')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->password_changed_by)),
                                    TextInput::make('created_by_type')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->created_by_type)),
                                    TextInput::make('created_by_admin_id')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->created_by_admin_id)),
                                    TextInput::make('blocked_by')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->blocked_by)),
                                    DateTimePicker::make('deleted_at')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->deleted_at)),
                                    TextInput::make('deleted_by')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->deleted_by)),
                                    TextInput::make('deleted_ip')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->visible(fn (?User $record): bool => filled($record?->deleted_ip)),
                                ])
                                ->columns(2)
                                ->collapsed(),
                        ]),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('ui.users.sections.identity'))
                ->schema([
                    TextEntry::make('name')
                        ->visible(fn (User $record): bool => self::canViewIdentity($record)),
                    TextEntry::make('email')
                        ->visible(fn (User $record): bool => self::canViewIdentity($record)),
                    TextEntry::make('username')
                        ->visible(fn (User $record): bool => self::canViewIdentity($record)),
                    TextEntry::make('role')
                        ->badge()
                        ->visible(fn (User $record): bool => self::canViewIdentity($record))
                        ->formatStateUsing(fn ($state): string => self::formatRole($state)),
                    TextEntry::make('account_status')
                        ->badge()
                        ->visible(fn (User $record): bool => self::canViewAccessStatus($record))
                        ->formatStateUsing(fn ($state): string => self::formatStatus($state)),
                ])
                ->columns(2)
                ->visible(fn (User $record): bool => self::canViewIdentity($record) || self::canViewAccessStatus($record)),
            Section::make(__('ui.users.sections.security'))
                ->schema([
                    TextEntry::make('must_change_password')
                        ->label(__('ui.users.fields.require_password_change'))
                        ->visible(fn (User $record): bool => self::canViewSecurity($record)),
                    TextEntry::make('password_expires_at')
                        ->dateTime()
                        ->visible(fn (User $record): bool => self::canViewSecurity($record)),
                    TextEntry::make('two_factor_enabled')
                        ->label(__('ui.users.fields.two_factor_enabled'))
                        ->visible(fn (User $record): bool => self::canViewSecurity($record)),
                    TextEntry::make('two_factor_method')
                        ->visible(fn (User $record): bool => self::canViewSecurity($record)),
                    TextEntry::make('security_stamp'),
                ])
                ->columns(2)
                ->visible(fn (User $record): bool => self::canViewSecurity($record)),
            Section::make(__('ui.users.sections.telemetry'))
                ->schema([
                    TextEntry::make('last_login_at')
                        ->dateTime()
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                    TextEntry::make('last_login_ip')
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                    TextEntry::make('last_failed_login_at')
                        ->dateTime()
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                    TextEntry::make('last_failed_login_ip')
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                    TextEntry::make('last_seen_at')
                        ->dateTime()
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                    TextEntry::make('last_seen_ip')
                        ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
                ])
                ->columns(2)
                ->visible(fn (User $record): bool => self::canViewSystemInfo($record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('60s')
            ->deferLoading()
            ->columns([
                ImageColumn::make('avatar')
                    ->label(__('ui.users.fields.avatar'))
                    ->disk(fn (User $record): string => self::resolveAvatarDiskForPath(self::stripAvatarDiskPrefix($record->avatar)))
                    ->getStateUsing(fn (User $record): ?string => self::stripAvatarDiskPrefix($record->avatar))
                    ->circular()
                    ->width(32)
                    ->height(32),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => self::canViewIdentity()),
                TextColumn::make('username')
                    ->label(__('ui.users.fields.username'))
                    ->searchable()
                    ->visible(fn (): bool => self::canViewIdentity()),
                TextColumn::make('phone_number')
                    ->label(__('ui.users.fields.phone'))
                    ->formatStateUsing(fn (User $record): string => trim(($record->phone_country_code ?: '').' '.($record->phone_number ?: '')))
                    ->searchable()
                    ->placeholder('—')
                    ->visible(fn (): bool => self::canViewIdentity()),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => self::canViewIdentity()),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatRole($state))
                    ->color(fn ($state): string => self::roleColor($state))
                    ->sortable()
                    ->visible(fn (): bool => self::canViewIdentity()),
                TextColumn::make('account_status')
                    ->label(__('ui.users.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatStatus($state))
                    ->color(fn ($state): string => self::statusColor($state))
                    ->sortable()
                    ->visible(fn (): bool => self::canViewAccessStatus()),
                IconColumn::make('two_factor_enabled')
                    ->label(__('ui.users.fields.two_factor'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewSecurity()),
                TextColumn::make('last_login_at')
                    ->label(__('ui.users.fields.last_login'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewSystemInfo()),
                TextColumn::make('last_seen_at')
                    ->label(__('ui.users.fields.last_seen'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewSystemInfo()),
                TextColumn::make('failed_login_attempts')
                    ->label(__('ui.users.fields.failed_attempts'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewSecurity()),
                TextColumn::make('locked_at')
                    ->label(__('ui.users.fields.locked_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewAccessStatus()),
                TextColumn::make('blocked_until')
                    ->label(__('ui.users.fields.blocked_until'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::canViewAccessStatus()),
                TextColumn::make('created_at')
                    ->label(__('ui.users.fields.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(fn (): array => collect(self::fallbackRoleNames())
                        ->mapWithKeys(fn (string $role): array => [$role => self::roleLabel($role)])
                        ->toArray())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->where('role', $value);
                    })
                    ->visible(fn (): bool => self::canViewIdentity()),
                SelectFilter::make('account_status')
                    ->label(__('ui.users.fields.status'))
                    ->options(AccountStatus::labels())
                    ->visible(fn (): bool => self::canViewAccessStatus()),
                Filter::make('locked')
                    ->label(__('ui.users.fields.locked_at'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereNotNull('locked_at')
                            ->orWhere('blocked_until', '>', now());
                    }))
                    ->visible(fn (): bool => self::canViewAccessStatus()),
                TrashedFilter::make(),
            ])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->emptyStateHeading(__('ui.users.empty.heading'))
            ->emptyStateDescription(__('ui.users.empty.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('ui.users.actions.create'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => self::canCreate()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('update', $record) ?? false),
                Action::make('unlock')
                    ->label(__('ui.users.actions.unlock'))
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize('execute_user_unlock')
                    ->visible(fn (User $record): bool => $record->isLocked()
                        && (AuthHelper::user()?->can('execute_user_unlock') ?? false))
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'failed_login_attempts' => 0,
                            'locked_at' => null,
                            'blocked_until' => null,
                        ])->save();
                    }),
                Action::make('activate')
                    ->label(__('ui.users.actions.activate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize('execute_user_activate')
                    ->visible(fn (User $record): bool => ! $record->isActive()
                        && (AuthHelper::user()?->can('execute_user_activate') ?? false))
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'account_status' => AccountStatus::Active,
                            'blocked_until' => null,
                            'blocked_reason' => null,
                            'blocked_by' => null,
                            'locked_at' => null,
                            'failed_login_attempts' => 0,
                        ])->save();
                    }),
                Action::make('force_password_reset')
                    ->label(__('ui.users.actions.force_password_reset'))
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize('execute_user_force_password_reset')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_force_password_reset') ?? false)
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'must_change_password' => true,
                            'password_expires_at' => now(),
                            'security_stamp' => Str::random(64),
                        ])->save();
                    }),
                Action::make('revoke_sessions')
                    ->label(__('ui.users.actions.revoke_sessions'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('execute_user_revoke_sessions')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_revoke_sessions') ?? false)
                    ->action(fn (User $record) => $record->rotateSecurityStamp()),
                Action::make('reset_2fa')
                    ->label(__('ui.users.actions.reset_2fa'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.users.modals.reset_2fa_heading'))
                    ->modalDescription(__('ui.users.modals.reset_2fa_description'))
                    ->authorize('execute_user_reset_2fa')
                    ->visible(fn (User $record): bool => $record->two_factor_enabled
                        && (AuthHelper::user()?->can('execute_user_reset_2fa') ?? false))
                    ->action(function (User $record): void {
                        $oldValues = [
                            'two_factor_enabled' => $record->two_factor_enabled,
                            'two_factor_method' => $record->two_factor_method,
                        ];

                        $record->forceFill([
                            'two_factor_enabled' => false,
                            'two_factor_secret' => null,
                            'two_factor_method' => null,
                            'two_factor_recovery_codes' => null,
                            'two_factor_confirmed_at' => null,
                            'security_stamp' => Str::random(64),
                        ])->save();

                        AuditLogWriter::writeAudit([
                            'user_id' => AuthHelper::id(),
                            'action' => 'user.2fa_reset',
                            'auditable_type' => User::class,
                            'auditable_id' => $record->id,
                            'old_values' => $oldValues,
                            'new_values' => ['two_factor_enabled' => false],
                            'ip_address' => request()->ip(),
                            'user_agent' => request()->userAgent(),
                            'context' => [
                                'target_user_id' => $record->id,
                                'target_email' => $record->email,
                                'performed_by' => AuthHelper::user()?->email,
                            ],
                        ]);
                    }),
                Action::make('force_logout')
                    ->label(__('ui.users.actions.force_logout'))
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.users.modals.force_logout_heading'))
                    ->modalDescription(__('ui.users.modals.force_logout_description'))
                    ->authorize('execute_user_force_logout')
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('execute_user_force_logout') ?? false)
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'security_stamp' => Str::random(64),
                            'remember_token' => null,
                        ])->save();

                        // Invalidate all sessions for this user
                        DB::table('sessions')
                            ->where('user_id', $record->id)
                            ->delete();

                        AuditLogWriter::writeAudit([
                            'user_id' => AuthHelper::id(),
                            'action' => 'user.force_logout',
                            'auditable_type' => User::class,
                            'auditable_id' => $record->id,
                            'old_values' => null,
                            'new_values' => ['sessions_invalidated' => true],
                            'ip_address' => request()->ip(),
                            'user_agent' => request()->userAgent(),
                            'context' => [
                                'target_user_id' => $record->id,
                                'target_email' => $record->email,
                                'performed_by' => AuthHelper::user()?->email,
                            ],
                        ]);
                    }),
                RestoreAction::make()
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('restore', $record) ?? false),
                ForceDeleteAction::make()
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('forceDelete', $record) ?? false),
                DeleteAction::make()
                    ->visible(fn (User $record): bool => AuthHelper::user()?->can('delete', $record) ?? false)
                    ->before(function (User $record): void {
                        $record->forceFill([
                            'deleted_by' => AuthHelper::id(),
                            'deleted_ip' => request()->ip(),
                        ])->save();
                    }),
            ])
            ->groupedBulkActions([
                DeleteBulkAction::make()
                    ->authorize('delete_any_user')
                    ->visible(fn (): bool => AuthHelper::user()?->can('delete_any_user') ?? false)
                    ->before(function (Collection $records): void {
                        $records->each(function (User $record): void {
                            $record->forceFill([
                                'deleted_by' => AuthHelper::id(),
                                'deleted_ip' => request()->ip(),
                            ])->save();
                        });
                    }),
                RestoreBulkAction::make()
                    ->authorize('restore_any_user')
                    ->visible(fn (): bool => AuthHelper::user()?->can('restore_any_user') ?? false),
                ForceDeleteBulkAction::make()
                    ->authorize('force_delete_any_user')
                    ->visible(fn (): bool => AuthHelper::user()?->can('force_delete_any_user') ?? false),
                BulkAction::make('bulk_activate')
                    ->label(__('ui.users.actions.activate_selected'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize('execute_user_activate')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_activate') ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(function (User $record): void {
                            if (! self::canActorManage($record)) {
                                return;
                            }
                            $record->forceFill([
                                'account_status' => AccountStatus::Active,
                                'blocked_until' => null,
                                'blocked_reason' => null,
                                'blocked_by' => null,
                                'locked_at' => null,
                                'failed_login_attempts' => 0,
                            ])->save();
                        });

                        self::logBulkAction('bulk_user_activate', $records);
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_suspend')
                    ->label(__('ui.users.actions.suspend_selected'))
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize('update_user')
                    ->visible(fn (): bool => AuthHelper::user()?->can('update_user') ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(function (User $record): void {
                            if (! self::canActorManage($record)) {
                                return;
                            }
                            $record->forceFill([
                                'account_status' => AccountStatus::Suspended,
                                'blocked_by' => AuthHelper::id(),
                                'blocked_reason' => __('ui.users.notes.bulk_suspension'),
                            ])->save();
                        });

                        self::logBulkAction('bulk_user_suspend', $records);
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_unlock')
                    ->label(__('ui.users.actions.unlock_selected'))
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize('execute_user_unlock')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_unlock') ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(function (User $record): void {
                            if (! self::canActorManage($record)) {
                                return;
                            }
                            $record->forceFill([
                                'failed_login_attempts' => 0,
                                'locked_at' => null,
                                'blocked_until' => null,
                            ])->save();
                        });

                        self::logBulkAction('bulk_user_unlock', $records);
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_force_password_reset')
                    ->label(__('ui.users.actions.force_password_reset_selected'))
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('execute_user_force_password_reset')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_force_password_reset') ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(function (User $record): void {
                            if (! self::canActorManage($record)) {
                                return;
                            }
                            $record->forceFill([
                                'must_change_password' => true,
                                'password_expires_at' => now(),
                                'security_stamp' => Str::random(64),
                            ])->save();
                        });

                        self::logBulkAction('bulk_user_force_password_reset', $records);
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->visible(fn (): bool => self::canCreate()),
            ]);
    }

    /**
     * Check if current actor can manage the target user (for bulk actions).
     */
    private static function canActorManage(User $target): bool
    {
        $actor = AuthHelper::user();
        if (! $actor instanceof User) {
            return false;
        }

        return $actor->canManageUser($target);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        $actor = AuthHelper::user();
        $developerRole = (string) config('security.developer_role', 'developer');
        if ($actor && ! $actor->isDeveloper()) {
            $query->where('role', '!=', $developerRole);
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        if (! (AuthHelper::user()?->can('create_user') ?? false)) {
            return false;
        }

        return self::canAssignRoles();
    }

    public static function canEdit(Model $record): bool
    {
        return AuthHelper::user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return AuthHelper::user()?->can('delete', $record) ?? false;
    }

    public static function canViewAny(): bool
    {
        return AuthHelper::user()?->can('view_any_user') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return AuthHelper::user()?->can('view', $record) ?? false;
    }

    public static function canViewAvatar(?User $record = null): bool
    {
        return self::canViewUserSection($record, ['manage_user_avatar', 'manage_user_identity']);
    }

    public static function canViewIdentity(?User $record = null): bool
    {
        return self::canViewUserSection($record, ['manage_user_identity']);
    }

    public static function canViewSecurity(?User $record = null): bool
    {
        return self::canViewUserSection($record, ['manage_user_security']);
    }

    public static function canViewAccessStatus(?User $record = null): bool
    {
        return self::canViewUserSection($record, ['manage_user_access_status']);
    }

    public static function canViewSystemInfo(?User $record = null): bool
    {
        return self::canViewUserSection($record, ['view_user_system_info']);
    }

    public static function canManageAvatar(?User $record = null): bool
    {
        return self::canManageUserSection($record, ['manage_user_avatar', 'manage_user_identity']);
    }

    public static function canManageIdentity(?User $record = null): bool
    {
        return self::canManageUserSection($record, ['manage_user_identity']);
    }

    public static function canManageSecurity(?User $record = null): bool
    {
        return self::canManageUserSection($record, ['manage_user_security']);
    }

    public static function canManageAccessStatus(?User $record = null): bool
    {
        return self::canManageUserSection($record, ['manage_user_access_status']);
    }

    private static function canViewUserSection(?User $record, array $permissions): bool
    {
        $cacheKey = 'view_'.implode('_', $permissions).'_'.($record?->id ?? 'null');
        if (isset(self::$permissionCache[$cacheKey])) {
            return self::$permissionCache[$cacheKey];
        }

        $actor = AuthHelper::user();
        if (! $actor instanceof User) {
            return self::$permissionCache[$cacheKey] = false;
        }

        if ($record) {
            if (! $actor->can('view', $record)) {
                return self::$permissionCache[$cacheKey] = false;
            }
        } elseif (! self::canViewAny() && ! self::canCreate()) {
            return self::$permissionCache[$cacheKey] = false;
        }

        if (method_exists($actor, 'hasElevatedPrivileges') && $actor->hasElevatedPrivileges()) {
            return self::$permissionCache[$cacheKey] = true;
        }

        foreach ($permissions as $permission) {
            if ($actor->can($permission)) {
                return self::$permissionCache[$cacheKey] = true;
            }
        }

        return self::$permissionCache[$cacheKey] = $actor->can('view_any_user') || $actor->can('view_user');
    }

    private static function canManageUserSection(?User $record, array $permissions): bool
    {
        $cacheKey = 'manage_'.implode('_', $permissions).'_'.($record?->id ?? 'null');
        if (isset(self::$permissionCache[$cacheKey])) {
            return self::$permissionCache[$cacheKey];
        }

        $actor = AuthHelper::user();
        if (! $actor instanceof User) {
            return self::$permissionCache[$cacheKey] = false;
        }

        if ($record) {
            if (! $actor->can('update', $record)) {
                return self::$permissionCache[$cacheKey] = false;
            }
        } elseif (! self::canCreate()) {
            return self::$permissionCache[$cacheKey] = false;
        }

        if (method_exists($actor, 'hasElevatedPrivileges') && $actor->hasElevatedPrivileges()) {
            return self::$permissionCache[$cacheKey] = true;
        }

        foreach ($permissions as $permission) {
            if ($actor->can($permission)) {
                return self::$permissionCache[$cacheKey] = true;
            }
        }

        return self::$permissionCache[$cacheKey] = $actor->can('update_user');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    private static function formatRole(?string $state): string
    {
        $value = (string) $state;

        return self::roleLabel($value);
    }

    private static function formatStatus(AccountStatus|string|null $state): string
    {
        $value = $state instanceof AccountStatus ? $state->value : (string) $state;

        return AccountStatus::labels()[$value] ?? $value;
    }

    private static function roleOptions(?User $record = null): array
    {
        $actor = AuthHelper::user();
        $roles = $actor instanceof User ? self::assignableRolesFor($actor) : [];

        if ($record?->role && ! in_array($record->role, $roles, true)) {
            $roles[] = $record->role;
        }

        if (empty($roles)) {
            $roles = self::fallbackRoleNames();
        }

        $roles = array_values(array_unique($roles));
        sort($roles);

        return collect($roles)
            ->mapWithKeys(fn (string $role): array => [$role => self::roleLabel($role)])
            ->toArray();
    }

    private static function defaultRoleName(): ?string
    {
        $preferred = 'user';
        $roles = self::fallbackRoleNames();

        if (in_array($preferred, $roles, true)) {
            return $preferred;
        }

        return $roles[0] ?? null;
    }

    private static function canAssignRoles(?User $record = null): bool
    {
        $actor = AuthHelper::user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($record && ($record->isDeveloper() || $record->isSuperAdmin()) && ! $actor->isDeveloper()) {
            return false;
        }

        return $actor->isDeveloper() || $actor->can('assign_roles');
    }

    private static function canManageUser(User $record): bool
    {
        return AuthHelper::user()?->can('update', $record) ?? false;
    }

    private static function roleLabel(string $role): string
    {
        $labels = [
            (string) config('security.developer_role', 'developer') => 'Developer',
            (string) config('security.superadmin_role', 'super_admin') => 'Super Admin',
            'admin' => 'Admin',
            'manager' => 'Manager',
            'user' => 'User',
        ];

        return $labels[$role] ?? Str::headline($role);
    }

    /**
     * @return array<int, string>
     */
    private static function fallbackRoleNames(): array
    {
        if (SchemaFacade::hasTable('roles')) {
            $names = Role::query()
                ->orderBy('name')
                ->pluck('name')
                ->all();

            if (! empty($names)) {
                return $names;
            }
        }

        return array_keys(config('security.role_hierarchy', []));
    }

    /**
     * @return array<int, string>
     */
    private static function assignableRolesFor(User $actor): array
    {
        if ($actor->isDeveloper()) {
            return self::fallbackRoleNames();
        }

        if (! $actor->can('assign_roles')) {
            return [];
        }

        $hierarchy = config('security.role_hierarchy', []);
        $actorRank = self::roleRankFor($actor);

        return collect(self::fallbackRoleNames())
            ->reject(fn (string $role): bool => $role === (string) config('security.developer_role', 'developer'))
            ->filter(function (string $role) use ($hierarchy, $actorRank): bool {
                $roleRank = $hierarchy[$role] ?? -1;

                return $roleRank >= 0 && $roleRank < $actorRank;
            })
            ->values()
            ->all();
    }

    public static function canAssignRoleName(string $role, ?User $actor, ?User $record = null): bool
    {
        if (! $actor) {
            return false;
        }

        if (! SchemaFacade::hasTable('roles') || ! Role::where('name', $role)->exists()) {
            return false;
        }

        $developerRole = (string) config('security.developer_role', 'developer');
        if ($record?->isDeveloper()) {
            return $role === $developerRole;
        }

        if ($record && ($record->isDeveloper() || $record->isSuperAdmin()) && ! $actor->isDeveloper()) {
            return false;
        }

        if ($actor->isDeveloper()) {
            return true;
        }

        if (! $actor->can('assign_roles')) {
            return false;
        }

        $hierarchy = config('security.role_hierarchy', []);
        $roleRank = $hierarchy[$role] ?? -1;

        if ($roleRank < 0) {
            return false;
        }

        return $roleRank < self::roleRankFor($actor);
    }

    private static function roleRankFor(User $user): int
    {
        $hierarchy = config('security.role_hierarchy', []);
        $roleNames = $user->getRoleNames();

        if ($roleNames->isEmpty() && $user->role) {
            $roleNames = collect([$user->role]);
        }

        return $roleNames
            ->map(fn (string $role): int => $hierarchy[$role] ?? -1)
            ->max() ?? -1;
    }

    private static function roleColor(?string $state): string
    {
        $value = (string) $state;

        $developerRole = (string) config('security.developer_role', 'developer');
        $superAdminRole = (string) config('security.superadmin_role', 'super_admin');

        return match ($value) {
            $developerRole => 'danger',
            $superAdminRole => 'primary',
            'admin' => 'primary',
            'manager' => 'warning',
            default => 'gray',
        };
    }

    private static function statusColor(AccountStatus|string|null $state): string
    {
        $value = $state instanceof AccountStatus ? $state->value : (string) $state;

        return match ($value) {
            AccountStatus::Active->value => 'success',
            AccountStatus::Blocked->value => 'danger',
            AccountStatus::Suspended->value => 'warning',
            default => 'gray',
        };
    }

    /**
     * Check if the target user is a protected user (Developer or SuperAdmin).
     */
    private static function isProtectedUser(User $user): bool
    {
        return $user->isDeveloper() || $user->isSuperAdmin();
    }

    /**
     * Check if the current actor has elevated privileges.
     */
    private static function actorHasElevatedPrivileges(): bool
    {
        $actor = AuthHelper::user();
        if (! $actor instanceof User) {
            return false;
        }

        return $actor->hasElevatedPrivileges();
    }

    private static function logBulkAction(string $action, Collection $records): void
    {
        $actorId = AuthHelper::id();
        $ids = $records->take(50)->pluck('id')->values()->all();

        AuditLogWriter::writeAudit([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'context' => [
                'count' => $records->count(),
                'ids' => $ids,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{disk: string|null, path: string|null, url: string|null}
     */
    private static function parseAvatarState(?string $state): array
    {
        if (! $state) {
            return [
                'disk' => null,
                'path' => null,
                'url' => null,
            ];
        }

        if (filter_var($state, FILTER_VALIDATE_URL) !== false) {
            return [
                'disk' => null,
                'path' => null,
                'url' => $state,
            ];
        }

        if (preg_match('/^([A-Za-z0-9_-]+):(.*)$/', $state, $matches) === 1) {
            return [
                'disk' => $matches[1] ?: null,
                'path' => $matches[2] ?: null,
                'url' => null,
            ];
        }

        return [
            'disk' => null,
            'path' => $state,
            'url' => null,
        ];
    }

    private static function resolveAvatarDiskForPath(?string $path, ?string $preferredDisk = null): string
    {
        $primary = (string) SystemSettings::getValue('storage.primary_disk', 'public');
        $fallback = (string) SystemSettings::getValue('storage.fallback_disk', 'public');

        if ($preferredDisk && self::isPublicDisk($preferredDisk)) {
            return $preferredDisk;
        }

        $path = self::normalizeAvatarPath($path);

        if (! $path) {
            return self::sanitizePublicDisk($primary)
                ?: (self::sanitizePublicDisk($fallback) ?: 'public');
        }

        try {
            if ($primary && self::isPublicDisk($primary) && Storage::disk($primary)->exists($path)) {
                return $primary;
            }
        } catch (\Throwable) {
            // Fall through to fallback.
        }

        return self::sanitizePublicDisk($fallback)
            ?: (self::sanitizePublicDisk($primary) ?: 'public');
    }

    private static function stripAvatarDiskPrefix(?string $state): ?string
    {
        $parsed = self::parseAvatarState($state);

        return $parsed['url'] ?: self::normalizeAvatarPath($parsed['path']);
    }

    public static function queueAvatarDeletion(?string $state): void
    {
        $parsed = self::parseAvatarState($state);
        $path = self::normalizeAvatarPath($parsed['path']);
        if (! $path) {
            return;
        }

        $disk = self::resolveAvatarDiskForPath($path, $parsed['disk']);

        $queue = Cache::get(self::AVATAR_DELETE_QUEUE, []);
        $queue[] = [
            'disk' => $disk,
            'path' => $path,
            'delete_at' => now()->addDays(7)->toDateTimeString(),
        ];

        Cache::put(self::AVATAR_DELETE_QUEUE, $queue, now()->addDays(8));
    }

    private static function normalizeAvatarPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_contains($path, '/')) {
            return $path;
        }

        return 'avatars/'.ltrim($path, '/');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function resolveAvatarUploadDisks(): array
    {
        $primary = (string) SystemSettings::getValue('storage.primary_disk', 'public');
        $fallback = (string) SystemSettings::getValue('storage.fallback_disk', 'public');

        $primary = self::sanitizePublicDisk($primary);
        $fallback = self::sanitizePublicDisk($fallback) ?: 'public';

        if (! $primary) {
            $primary = $fallback;
        }

        return [$primary, $fallback];
    }

    private static function isPublicDisk(?string $disk): bool
    {
        return self::sanitizePublicDisk($disk) !== null;
    }

    private static function sanitizePublicDisk(?string $disk): ?string
    {
        if (! $disk) {
            return null;
        }

        $disks = config('filesystems.disks', []);
        if (! is_array($disks) || ! isset($disks[$disk])) {
            return null;
        }

        $config = $disks[$disk];
        if (! is_array($config)) {
            return null;
        }

        if (($config['visibility'] ?? null) === 'public') {
            return $disk;
        }

        if (! empty($config['url'])) {
            return $disk;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function asianCountryDialCodes(): array
    {
        return [
            '+62' => 'Indonesia (+62)',
            '+60' => 'Malaysia (+60)',
            '+65' => 'Singapore (+65)',
            '+66' => 'Thailand (+66)',
            '+63' => 'Philippines (+63)',
            '+84' => 'Vietnam (+84)',
            '+673' => 'Brunei (+673)',
            '+855' => 'Cambodia (+855)',
            '+856' => 'Laos (+856)',
            '+95' => 'Myanmar (+95)',
            '+852' => 'Hong Kong (+852)',
            '+853' => 'Macau (+853)',
            '+886' => 'Taiwan (+886)',
            '+81' => 'Japan (+81)',
            '+82' => 'South Korea (+82)',
            '+86' => 'China (+86)',
            '+91' => 'India (+91)',
            '+92' => 'Pakistan (+92)',
            '+94' => 'Sri Lanka (+94)',
            '+880' => 'Bangladesh (+880)',
            '+975' => 'Bhutan (+975)',
            '+977' => 'Nepal (+977)',
            '+976' => 'Mongolia (+976)',
        ];
    }

    private static function detectCountryDialCode(): string
    {
        $locale = self::detectLocale();
        $region = $locale ? strtoupper((string) str($locale)->afterLast('-')) : null;

        $map = [
            'ID' => '+62',
            'MY' => '+60',
            'SG' => '+65',
            'TH' => '+66',
            'PH' => '+63',
            'VN' => '+84',
            'BN' => '+673',
            'KH' => '+855',
            'LA' => '+856',
            'MM' => '+95',
            'HK' => '+852',
            'MO' => '+853',
            'TW' => '+886',
            'JP' => '+81',
            'KR' => '+82',
            'CN' => '+86',
            'IN' => '+91',
            'PK' => '+92',
            'LK' => '+94',
            'BD' => '+880',
            'BT' => '+975',
            'NP' => '+977',
            'MN' => '+976',
        ];

        return $map[$region] ?? '+62';
    }

    public static function detectLocale(): ?string
    {
        $raw = request()?->header('Accept-Language');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $primary = trim(explode(',', $raw)[0] ?? '');

        return $primary !== '' ? $primary : null;
    }

    public static function detectTimezone(): ?string
    {
        return request()?->header('X-Timezone') ?: null;
    }
}

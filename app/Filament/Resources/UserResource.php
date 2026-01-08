<?php

namespace App\Filament\Resources;

use App\Enums\AccountStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
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
use Filament\Forms\Components\BaseFileUpload;
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
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    private const AVATAR_DELETE_QUEUE = 'user_avatar_delete_queue';

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $isViewer = (AuthHelper::user()?->hasRole('viewer') ?? false)
            || (AuthHelper::user()?->role === 'viewer');
        $disk = (string) SystemSettings::getValue('storage.primary_disk', 'public');
        $fallbackDisk = (string) SystemSettings::getValue('storage.fallback_disk', 'public');
        $localeFallback = (string) config('app.locale', 'en');
        $timezoneFallback = (string) config('app.timezone', 'UTC');
        $countryOptions = self::asianCountryDialCodes();

        return $schema->components([
            \Filament\Schemas\Components\Tabs::make('User')
                ->persistTabInQueryString()
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make('Data Utama')
                        ->icon('heroicon-o-user')
                        ->schema([
                            Section::make('Avatar')
                                ->description('Foto profil pengguna untuk konsistensi identitas di seluruh panel.')
                                ->schema([
                                    FileUpload::make('avatar')
                                        ->label('Avatar')
                                        ->disk($disk)
                                        ->directory('avatars')
                                        ->avatar()
                                        ->imageEditor()
                                        ->imagePreviewHeight('44')
                                        ->imageResizeTargetWidth('512')
                                        ->imageResizeTargetHeight('512')
                                        ->maxSize(512)
                                        ->visibility('public')
                                        ->helperText('PNG/JPG/WebP. Disimpan di storage utama, auto resize agar tajam dan ringan.')
                                        ->disabled(fn (): bool => $isViewer)
                                        ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
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
                                                $storage = Storage::disk($diskName);
                                                if (! $storage->exists($path)) {
                                                    return null;
                                                }

                                                return [
                                                    'name' => is_array($storedFileNames) ? ($storedFileNames[$path] ?? basename($path)) : ($storedFileNames ?? basename($path)),
                                                    'size' => (int) $storage->size($path),
                                                    'type' => $storage->mimeType($path),
                                                    'url' => $storage->url($path),
                                                ];
                                            } catch (\Throwable) {
                                                return null;
                                            }
                                        })
                                        ->saveUploadedFileUsing(function (BaseFileUpload $component, TemporaryUploadedFile $file) use ($disk, $fallbackDisk): ?string {
                                            $directory = (string) $component->getDirectory();
                                            $filename = (string) $component->getUploadedFileNameForStorage($file);
                                            $storeMethod = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';

                                            try {
                                                return $file->{$storeMethod}($directory, $filename, $disk);
                                            } catch (\Throwable) {
                                                $fallback = $fallbackDisk ?: 'public';

                                                return $file->{$storeMethod}($directory, $filename, $fallback);
                                            }
                                        }),
                                ])
                                ->columns(1),
                            Section::make('Identity')
                                ->description('Informasi inti akun pengguna untuk identitas dan peran.')
                                ->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->maxLength(255)
                                        ->disabled(fn (?User $record): bool => $isViewer || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges())),
                                    TextInput::make('email')
                                        ->email()
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(ignoreRecord: true)
                                        ->disabled(fn (?User $record): bool => $isViewer || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges())),
                                    TextInput::make('username')
                                        ->maxLength(50)
                                        ->unique(ignoreRecord: true)
                                        ->disabled(fn (string $operation, ?User $record): bool => $isViewer || $operation === 'create' || ($record && self::isProtectedUser($record) && ! self::actorHasElevatedPrivileges()))
                                        ->helperText('Username diatur oleh pengguna setelah aktivasi.'),
                                    TextInput::make('position')
                                        ->maxLength(100)
                                        ->disabled(fn (): bool => $isViewer),
                                    Select::make('role')
                                        ->label('Role')
                                        ->options(fn (?User $record): array => self::roleOptions($record))
                                        ->required(fn (): bool => self::canAssignRoles())
                                        ->native(false)
                                        ->searchable()
                                        ->default(fn (): ?string => self::defaultRoleName())
                                        ->disabled(fn (?User $record): bool => $isViewer || ! self::canAssignRoles($record) || ($record?->isDeveloper() ?? false))
                                        ->helperText(fn (?User $record): ?string => $record?->isDeveloper() ? 'Developer role is immutable.' : null),
                                    Select::make('phone_country_code')
                                        ->label('Kode Negara')
                                        ->options($countryOptions)
                                        ->default(fn (): string => self::detectCountryDialCode())
                                        ->native(false)
                                        ->searchable()
                                        ->required()
                                        ->disabled(fn (): bool => $isViewer),
                                    TextInput::make('phone_number')
                                        ->label('Nomor HP')
                                        ->tel()
                                        ->numeric()
                                        ->rules(['nullable', 'regex:/^[0-9]{6,20}$/'])
                                        ->maxLength(20)
                                        ->helperText('Hanya angka, tanpa spasi atau simbol.')
                                        ->disabled(fn (): bool => $isViewer),
                                ])
                                ->columns(2),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make('Status & Keamanan')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make('Security')
                                ->description('Kredensial, 2FA, dan kebijakan keamanan pengguna.')
                                ->schema([
                                    TextInput::make('password')
                                        ->password()
                                        ->revealable()
                                        ->visible(fn (string $operation): bool => $operation === 'edit')
                                        ->rules(function (Get $get, ?User $record, string $operation): array {
                                            if ($operation !== 'edit' || blank($get('password'))) {
                                                return [];
                                            }

                                            return PasswordRules::build($record);
                                        })
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
                                        ->password()
                                        ->dehydrated(false)
                                        ->same('password')
                                        ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password')))
                                        ->required(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password'))),
                                    Toggle::make('must_change_password')
                                        ->label('Require Password Change')
                                        ->disabled(fn (): bool => $isViewer),
                                    DateTimePicker::make('password_expires_at')
                                        ->label('Password Expires At')
                                        ->disabled(fn (): bool => $isViewer)
                                        ->helperText(function (Get $get): string {
                                            $value = $get('password_expires_at');
                                            if (! $value) {
                                                return 'Akan otomatis diisi saat password diubah.';
                                            }

                                            try {
                                                $target = \Carbon\Carbon::parse($value);
                                            } catch (\Throwable) {
                                                return 'Akan otomatis diisi saat password diubah.';
                                            }

                                            $now = now();
                                            $seconds = $now->diffInSeconds($target, false);
                                            $days = (int) ceil(abs($seconds) / 86400);

                                            if ($seconds <= 0) {
                                                return $days === 0 ? 'Kedaluwarsa hari ini.' : "Kedaluwarsa {$days} hari lalu.";
                                            }

                                            return $days === 0 ? 'Kedaluwarsa hari ini.' : "Kedaluwarsa {$days} hari lagi.";
                                        }),
                                    Toggle::make('two_factor_enabled')
                                        ->label('Two-Factor Enabled')
                                        ->disabled(fn (): bool => $isViewer)
                                        ->live()
                                        ->helperText('Nonaktifkan untuk menghapus metode 2FA yang tersimpan.'),
                                    Select::make('two_factor_method')
                                        ->label('Two-Factor Method')
                                        ->options([
                                            'app' => 'Authenticator App',
                                            'sms' => 'SMS',
                                            'email' => 'Email (Gmail)',
                                        ])
                                        ->native(false)
                                        ->live()
                                        ->disabled(fn (Get $get): bool => $isViewer || ! $get('two_factor_enabled'))
                                        ->required(fn (Get $get): bool => (bool) $get('two_factor_enabled')),
                                    TextInput::make('security_stamp')
                                        ->label('Security Stamp')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->helperText('Berubah saat password atau sesi di-rotate.'),
                                ])
                                ->columns(2),
                            Section::make('Access Status')
                                ->schema([
                                    Select::make('account_status')
                                        ->options(AccountStatus::labels())
                                        ->required()
                                        ->native(false)
                                        ->default(AccountStatus::Active->value)
                                        ->disabled(fn (?User $record): bool => $isViewer || ($record ? ! self::canManageUser($record) : false))
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

                                            $template = "Status: {$status}\n"
                                                ."Alasan: \n"
                                                ."Tindakan: \n"
                                                ."PIC: \n"
                                                .'Tanggal: '.now()->format('Y-m-d');
                                            $set('blocked_reason', $template);
                                        }),
                                    DateTimePicker::make('blocked_until')
                                        ->disabled(fn (): bool => $isViewer),
                                    RichEditor::make('blocked_reason')
                                        ->label('Status Reason')
                                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                                        ->required(fn (Get $get): bool => in_array($get('account_status'), [
                                            AccountStatus::Blocked->value,
                                            AccountStatus::Suspended->value,
                                            AccountStatus::Terminated->value,
                                        ], true))
                                        ->disabled(fn (): bool => $isViewer)
                                        ->columnSpanFull(),
                                    TextInput::make('blocked_by')
                                        ->label('Blocked By (User ID)')
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
                    \Filament\Schemas\Components\Tabs\Tab::make('System Info')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('Telemetry')
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
                                ])
                                ->columns(2)
                                ->collapsed(),
                            Section::make('Audit')
                                ->schema([
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
            Section::make('Identity')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('username'),
                    TextEntry::make('role')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => self::formatRole($state)),
                    TextEntry::make('account_status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => self::formatStatus($state)),
                ])
                ->columns(2),
            Section::make('Security')
                ->schema([
                    TextEntry::make('must_change_password')->label('Require Password Change'),
                    TextEntry::make('password_expires_at')->dateTime(),
                    TextEntry::make('two_factor_enabled')->label('Two-Factor Enabled'),
                    TextEntry::make('two_factor_method'),
                    TextEntry::make('security_stamp'),
                ])
                ->columns(2),
            Section::make('Telemetry')
                ->schema([
                    TextEntry::make('last_login_at')->dateTime(),
                    TextEntry::make('last_login_ip'),
                    TextEntry::make('last_failed_login_at')->dateTime(),
                    TextEntry::make('last_failed_login_ip'),
                    TextEntry::make('last_seen_at')->dateTime(),
                    TextEntry::make('last_seen_ip'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->disk(fn (User $record): string => self::resolveAvatarDiskForPath(self::stripAvatarDiskPrefix($record->avatar)))
                    ->getStateUsing(fn (User $record): ?string => self::stripAvatarDiskPrefix($record->avatar))
                    ->circular()
                    ->size(32),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->label('Nomor HP')
                    ->formatStateUsing(fn (User $record): string => trim(($record->phone_country_code ?: '').' '.($record->phone_number ?: '')))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatRole($state))
                    ->color(fn ($state): string => self::roleColor($state))
                    ->sortable(),
                TextColumn::make('account_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::formatStatus($state))
                    ->color(fn ($state): string => self::statusColor($state))
                    ->sortable(),
                IconColumn::make('two_factor_enabled')
                    ->label('2FA')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failed_login_attempts')
                    ->label('Failed Attempts')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('locked_at')
                    ->label('Locked At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blocked_until')
                    ->label('Blocked Until')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
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
                    }),
                SelectFilter::make('account_status')
                    ->label('Status')
                    ->options(AccountStatus::labels()),
                Filter::make('locked')
                    ->label('Locked')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereNotNull('locked_at')
                            ->orWhere('blocked_until', '>', now());
                    })),
                TrashedFilter::make(),
            ])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->emptyStateHeading('Belum ada pengguna terdaftar')
            ->emptyStateDescription('Buat akun pertama untuk mulai mengelola peran, audit, dan akses.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Buat pengguna baru')
                    ->icon('heroicon-o-user-plus'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('unlock')
                    ->label('Unlock')
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
                    ->label('Activate')
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
                    ->label('Force Password Reset')
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
                    ->label('Revoke Sessions')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('execute_user_revoke_sessions')
                    ->visible(fn (): bool => AuthHelper::user()?->can('execute_user_revoke_sessions') ?? false)
                    ->action(fn (User $record) => $record->rotateSecurityStamp()),
                RestoreAction::make(),
                ForceDeleteAction::make(),
                DeleteAction::make()
                    ->before(function (User $record): void {
                        $record->forceFill([
                            'deleted_by' => AuthHelper::id(),
                            'deleted_ip' => request()->ip(),
                        ])->save();
                    }),
            ])
            ->bulkActions([
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
                    ->label('Activate Selected')
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
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_suspend')
                    ->label('Suspend Selected')
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
                                'blocked_reason' => 'Bulk suspension',
                            ])->save();
                        });
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_unlock')
                    ->label('Unlock Selected')
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
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('bulk_force_password_reset')
                    ->label('Force Password Reset')
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
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->toolbarActions([
                CreateAction::make(),
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

        if ($preferredDisk) {
            return $preferredDisk;
        }

        $path = self::normalizeAvatarPath($path);

        if (! $path) {
            return $primary ?: ($fallback ?: 'public');
        }

        try {
            if ($primary && Storage::disk($primary)->exists($path)) {
                return $primary;
            }
        } catch (\Throwable) {
            // Fall through to fallback.
        }

        return $fallback ?: ($primary ?: 'public');
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

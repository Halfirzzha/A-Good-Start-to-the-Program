<?php

namespace App\Filament\Resources;

use App\Enums\AccountStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\LoginActivitiesRelationManager;
use App\Models\User;
use App\Support\PasswordRules;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | \UnitEnum | null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    TextInput::make('username')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Username diatur oleh pengguna setelah aktivasi.'),
                    TextInput::make('position')
                        ->maxLength(100),
                    Select::make('role')
                        ->label('Role')
                        ->options(fn (?User $record): array => self::roleOptions($record))
                        ->required(fn (): bool => self::canAssignRoles())
                        ->native(false)
                        ->searchable()
                        ->default(fn (): ?string => self::defaultRoleName())
                        ->disabled(fn (?User $record): bool => ! self::canAssignRoles($record) || ($record?->isDeveloper() ?? false)),
                    TextInput::make('avatar')
                        ->label('Avatar URL')
                        ->url()
                        ->maxLength(2048),
                ])
                ->columns(2),
            Section::make('Contact')
                ->schema([
                    TextInput::make('phone_country_code')
                        ->maxLength(5)
                        ->default('+62'),
                    TextInput::make('phone_number')
                        ->tel()
                        ->maxLength(20),
                    TextInput::make('timezone')
                        ->maxLength(64),
                    TextInput::make('locale')
                        ->maxLength(10),
                ])
                ->columns(2),
            Section::make('Security')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->rules(function (Get $get, ?User $record, string $operation): array {
                            if ($operation !== 'edit' || blank($get('password'))) {
                                return [];
                            }

                            return PasswordRules::build($record);
                        })
                        ->dehydrated(fn ($state): bool => filled($state)),
                    TextInput::make('password_confirmation')
                        ->password()
                        ->dehydrated(false)
                        ->same('password')
                        ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password')))
                        ->required(fn (Get $get, string $operation): bool => $operation === 'edit' && filled($get('password'))),
                    Toggle::make('must_change_password')
                        ->label('Require Password Change'),
                    DateTimePicker::make('password_expires_at')
                        ->label('Password Expires At'),
                    Toggle::make('two_factor_enabled')
                        ->label('Two-Factor Enabled'),
                    Select::make('two_factor_method')
                        ->options([
                            'app' => 'Authenticator App',
                            'sms' => 'SMS',
                            'email' => 'Email',
                        ])
                        ->native(false)
                        ->disabled(fn (Get $get): bool => ! $get('two_factor_enabled'))
                        ->required(fn (Get $get): bool => (bool) $get('two_factor_enabled')),
                ])
                ->columns(2),
            Section::make('Access Status')
                ->schema([
                    Select::make('account_status')
                        ->options(AccountStatus::labels())
                        ->required()
                        ->native(false)
                        ->default(AccountStatus::Active->value)
                        ->disabled(fn (?User $record): bool => $record ? ! self::canManageUser($record) : false),
                    DateTimePicker::make('blocked_until'),
                    Textarea::make('blocked_reason')
                        ->label('Status Reason')
                        ->rows(3)
                        ->maxLength(500)
                        ->required(fn (Get $get): bool => in_array($get('account_status'), [
                            AccountStatus::Blocked->value,
                            AccountStatus::Suspended->value,
                            AccountStatus::Terminated->value,
                        ], true))
                        ->columnSpanFull(),
                    DateTimePicker::make('locked_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('failed_login_attempts')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),
            Section::make('Telemetry')
                ->schema([
                    DateTimePicker::make('first_login_at')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('last_login_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('last_login_ip')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('last_failed_login_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('last_failed_login_ip')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('last_seen_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('last_seen_ip')
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2)
                ->collapsed(),
            Section::make('Audit')
                ->schema([
                    TextInput::make('security_stamp')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('password_changed_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('password_changed_by')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('created_by_type')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('created_by_admin_id')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('blocked_by')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('deleted_at')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('deleted_by')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('deleted_ip')
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2)
                ->collapsed(),
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
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->emptyStateHeading('Belum ada pengguna terdaftar')
            ->emptyStateDescription('Buat akun pertama untuk mulai mengelola peran, audit, dan akses.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Buat pengguna baru')
                    ->icon('heroicon-o-user-plus')
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
                        && (auth()->user()?->can('execute_user_unlock') ?? false))
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
                        && (auth()->user()?->can('execute_user_activate') ?? false))
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
                    ->visible(fn (): bool => auth()->user()?->can('execute_user_force_password_reset') ?? false)
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
                    ->visible(fn (): bool => auth()->user()?->can('execute_user_revoke_sessions') ?? false)
                    ->action(fn (User $record) => $record->rotateSecurityStamp()),
                RestoreAction::make(),
                ForceDeleteAction::make(),
                DeleteAction::make()
                    ->before(function (User $record): void {
                        $record->forceFill([
                            'deleted_by' => auth()->id(),
                            'deleted_ip' => request()->ip(),
                        ])->save();
                    }),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function canCreate(): bool
    {
        if (! (auth()->user()?->can('create_user') ?? false)) {
            return false;
        }

        return self::canAssignRoles();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) ?? false;
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
            LoginActivitiesRelationManager::class,
        ];
    }

    private static function formatRole(string | null $state): string
    {
        $value = (string) $state;

        return self::roleLabel($value);
    }

    private static function formatStatus(AccountStatus | string | null $state): string
    {
        $value = $state instanceof AccountStatus ? $state->value : (string) $state;

        return AccountStatus::labels()[$value] ?? $value;
    }

    private static function roleOptions(?User $record = null): array
    {
        $actor = auth()->user();
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
        $actor = auth()->user();

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
        return auth()->user()?->can('update', $record) ?? false;
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

    private static function roleColor(string | null $state): string
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

    private static function statusColor(AccountStatus | string | null $state): string
    {
        $value = $state instanceof AccountStatus ? $state->value : (string) $state;

        return match ($value) {
            AccountStatus::Active->value => 'success',
            AccountStatus::Blocked->value => 'danger',
            AccountStatus::Suspended->value => 'warning',
            default => 'gray',
        };
    }

}

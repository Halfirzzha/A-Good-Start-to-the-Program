<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use App\Support\AuditLogWriter;
use App\Support\PasswordRules;
use App\Support\SecurityService;
use App\Support\SystemSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Enterprise Profile Page with Granular RBAC.
 * Self-management only - field yang ditampilkan hanya yang aman untuk user sendiri.
 */
class EditProfile extends BaseEditProfile
{
    /** @var array<string, bool> Permission cache to avoid repeated checks */
    private array $permissionCache = [];

    public static function getLabel(): string
    {
        return __('ui.auth.profile.label');
    }

    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = $this->getUser();
        $canIdentity = $this->canManageIdentity();
        $canAvatar = $this->canManageAvatar();
        $canSecurity = $this->canManageSecurity();
        [$disk, $fallbackDisk] = $this->resolveAvatarUploadDisks();

        return $schema->components([
            Tabs::make('Profil')
                ->persistTabInQueryString()
                ->tabs([
                    // =========================================================
                    // Tab 1: Profile Information
                    // =========================================================
                    Tab::make(__('ui.auth.profile.tabs.profile'))
                        ->icon('heroicon-o-user-circle')
                        ->schema([
                            Section::make(__('ui.auth.profile.sections.profile'))
                                ->description(__('ui.auth.profile.descriptions.profile'))
                                ->schema([
                                    FileUpload::make('avatar')
                                        ->label(__('ui.auth.profile.fields.avatar'))
                                        ->disk($disk)
                                        ->directory('avatars')
                                        ->avatar()
                                        ->imageEditor()
                                        ->imagePreviewHeight('72')
                                        ->imageResizeTargetWidth('512')
                                        ->imageResizeTargetHeight('512')
                                        ->maxSize(512)
                                        ->visibility('public')
                                        ->helperText(__('ui.auth.profile.helpers.avatar'))
                                        ->disabled(fn (): bool => ! $canAvatar)
                                        ->saveUploadedFileUsing(function ($component, $file) use ($disk, $fallbackDisk): ?string {
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
                                    TextInput::make('name')
                                        ->label(__('ui.auth.profile.fields.name'))
                                        ->required()
                                        ->maxLength(255)
                                        ->prefixIcon('heroicon-o-identification')
                                        ->disabled(fn (): bool => ! $canIdentity),
                                    TextInput::make('username')
                                        ->label(__('ui.auth.profile.fields.username'))
                                        ->maxLength(50)
                                        ->prefixIcon('heroicon-o-at-symbol')
                                        ->disabled()
                                        ->helperText(__('ui.auth.profile.helpers.username_readonly')),
                                    TextInput::make('position')
                                        ->label(__('ui.auth.profile.fields.position'))
                                        ->maxLength(100)
                                        ->prefixIcon('heroicon-o-briefcase')
                                        ->disabled(fn (): bool => ! $canIdentity),
                                    Select::make('locale')
                                        ->label(__('ui.auth.profile.fields.language'))
                                        ->options([
                                            'id' => 'Bahasa Indonesia',
                                            'en' => 'English',
                                        ])
                                        ->native(false)
                                        ->default(fn () => $user->locale ?: config('app.locale', 'en'))
                                        ->disabled(fn (): bool => ! $canIdentity),
                                ])
                                ->columns(2),
                        ]),

                    // =========================================================
                    // Tab 2: Security & Password
                    // =========================================================
                    Tab::make(__('ui.auth.profile.tabs.security'))
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make(__('ui.auth.profile.sections.security'))
                                ->description(__('ui.auth.profile.descriptions.security'))
                                ->schema([
                                    TextInput::make('email')
                                        ->label(__('ui.auth.profile.fields.email'))
                                        ->email()
                                        ->required()
                                        ->maxLength(255)
                                        ->prefixIcon('heroicon-o-envelope')
                                        ->disabled(fn (): bool => ! $canIdentity),
                                    TextInput::make('phone_country_code')
                                        ->label(__('ui.auth.profile.fields.country_code'))
                                        ->datalist($this->countryDialCodes())
                                        ->placeholder('+62')
                                        ->prefixIcon('heroicon-o-flag')
                                        ->disabled(fn (): bool => ! $canIdentity)
                                        ->maxLength(6),
                                    TextInput::make('phone_number')
                                        ->label(__('ui.auth.profile.fields.phone'))
                                        ->tel()
                                        ->numeric()
                                        ->rules(['nullable', 'regex:/^[0-9]{6,20}$/'])
                                        ->maxLength(20)
                                        ->prefixIcon('heroicon-o-device-phone-mobile')
                                        ->disabled(fn (): bool => ! $canIdentity),
                                ])
                                ->columns(2),

                            Section::make(__('ui.auth.profile.sections.password_change'))
                                ->description(new HtmlString(PasswordRules::requirements()))
                                ->schema([
                                    TextInput::make('password')
                                        ->label(__('ui.auth.profile.fields.password_new'))
                                        ->password()
                                        ->revealable(filament()->arePasswordsRevealable())
                                        ->rules(fn () => PasswordRules::buildForProfile($user))
                                        ->showAllValidationMessages()
                                        ->autocomplete('new-password')
                                        ->dehydrated(fn ($state): bool => filled($state))
                                        ->dehydrateStateUsing(fn ($state): string => Hash::make($state))
                                        ->live(debounce: 500)
                                        ->same('passwordConfirmation')
                                        ->prefixIcon('heroicon-o-key')
                                        ->helperText(PasswordRules::requirements())
                                        ->visible(fn (): bool => $canSecurity),
                                    TextInput::make('passwordConfirmation')
                                        ->label(__('ui.auth.profile.fields.password_confirm'))
                                        ->password()
                                        ->autocomplete('new-password')
                                        ->revealable(filament()->arePasswordsRevealable())
                                        ->required()
                                        ->prefixIcon('heroicon-o-lock-closed')
                                        ->visible(fn (Get $get): bool => $canSecurity && filled($get('password')))
                                        ->dehydrated(false),
                                    TextInput::make('currentPassword')
                                        ->label(__('ui.auth.profile.fields.password_current'))
                                        ->password()
                                        ->autocomplete('current-password')
                                        ->currentPassword(guard: Filament::getAuthGuard())
                                        ->revealable(filament()->arePasswordsRevealable())
                                        ->required()
                                        ->prefixIcon('heroicon-o-shield-check')
                                        ->visible(fn (Get $get): bool => ($canSecurity || $canIdentity)
                                            && (filled($get('password')) || ($get('email') !== $this->getUser()->getAttributeValue('email'))))
                                        ->dehydrated(false),
                                ])
                                ->columns(2)
                                ->visible(fn (): bool => $canSecurity),

                            Section::make(__('ui.auth.profile.sections.two_factor'))
                                ->description(__('ui.auth.profile.descriptions.two_factor'))
                                ->schema([
                                    Toggle::make('two_factor_enabled')
                                        ->label(__('ui.auth.profile.fields.two_factor_enabled'))
                                        ->helperText(__('ui.auth.profile.helpers.two_factor'))
                                        ->disabled(fn (): bool => ! $canSecurity)
                                        ->live(),
                                    Select::make('two_factor_method')
                                        ->label(__('ui.auth.profile.fields.two_factor_method'))
                                        ->options([
                                            'email' => 'Email OTP',
                                        ])
                                        ->native(false)
                                        ->visible(fn (Get $get): bool => (bool) $get('two_factor_enabled'))
                                        ->disabled(fn (): bool => ! $canSecurity)
                                        ->required(fn (Get $get): bool => (bool) $get('two_factor_enabled')),
                                ])
                                ->columns(2)
                                ->visible(fn (): bool => $canSecurity),
                        ]),

                    // =========================================================
                    // Tab 3: Account Status & Activity (Read-only)
                    // =========================================================
                    Tab::make(__('ui.auth.profile.tabs.account'))
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Section::make(__('ui.auth.profile.sections.account_status'))
                                ->description(__('ui.auth.profile.descriptions.account_status'))
                                ->schema([
                                    Placeholder::make('account_status_display')
                                        ->label(__('ui.auth.profile.fields.account_status'))
                                        ->content(fn () => $this->formatAccountStatus($user)),
                                    Placeholder::make('role_display')
                                        ->label(__('ui.auth.profile.fields.role'))
                                        ->content(fn () => Str::headline($user->role ?? 'user')),
                                    Placeholder::make('email_verified_at')
                                        ->label(__('ui.auth.profile.fields.email_verified'))
                                        ->content(fn () => $user->email_verified_at
                                            ? $user->email_verified_at->format('d M Y H:i')
                                            : __('ui.auth.profile.values.not_verified')),
                                    Placeholder::make('member_since')
                                        ->label(__('ui.auth.profile.fields.member_since'))
                                        ->content(fn () => $user->created_at?->format('d M Y')),
                                ])
                                ->columns(2),

                            Section::make(__('ui.auth.profile.sections.recent_activity'))
                                ->description(__('ui.auth.profile.descriptions.recent_activity'))
                                ->schema([
                                    Placeholder::make('last_login_at')
                                        ->label(__('ui.auth.profile.fields.last_login'))
                                        ->content(fn () => $user->last_login_at
                                            ? $user->last_login_at->format('d M Y H:i').' ('.$user->last_login_at->diffForHumans().')'
                                            : '-'),
                                    Placeholder::make('last_seen_at')
                                        ->label(__('ui.auth.profile.fields.last_seen'))
                                        ->content(fn () => $user->last_seen_at
                                            ? $user->last_seen_at->format('d M Y H:i').' ('.$user->last_seen_at->diffForHumans().')'
                                            : '-'),
                                    Placeholder::make('password_changed_at')
                                        ->label(__('ui.auth.profile.fields.password_changed'))
                                        ->content(fn () => $user->password_changed_at
                                            ? $user->password_changed_at->format('d M Y H:i')
                                            : __('ui.auth.profile.values.never')),
                                    Placeholder::make('password_expires_at')
                                        ->label(__('ui.auth.profile.fields.password_expires'))
                                        ->content(fn () => $this->formatPasswordExpiry($user)),
                                ])
                                ->columns(2)
                                ->collapsed(),
                        ]),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $allowed = [];

        if ($this->canManageAvatar()) {
            $allowed[] = 'avatar';
        }

        if ($this->canManageIdentity()) {
            $allowed = array_merge($allowed, [
                'name',
                'email',
                'position',
                'phone_country_code',
                'phone_number',
                'locale',
            ]);
        }

        if ($this->canManageSecurity()) {
            $allowed = array_merge($allowed, [
                'password',
                'two_factor_enabled',
                'two_factor_method',
            ]);
        }

        $filtered = Arr::only($data, $allowed);

        // Clear 2FA method if disabled
        if (isset($filtered['two_factor_enabled']) && ! $filtered['two_factor_enabled']) {
            $filtered['two_factor_method'] = null;
        }

        return $filtered;
    }

    protected function afterSave(): void
    {
        $this->auditProfileUpdate();

        Notification::make()
            ->title(__('ui.auth.profile.notifications.updated'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        if (! $this->hasEditableFields()) {
            return [$this->getCancelFormAction()];
        }

        return parent::getFormActions();
    }

    // =========================================================================
    // Permission Helpers
    // =========================================================================

    private function hasEditableFields(): bool
    {
        return $this->canManageAvatar()
            || $this->canManageIdentity()
            || $this->canManageSecurity();
    }

    private function canManageAvatar(): bool
    {
        return $this->permissionCache['avatar'] ??= $this->checkPermission('manage_user_avatar');
    }

    private function canManageIdentity(): bool
    {
        return $this->permissionCache['identity'] ??= $this->checkPermission('manage_user_identity');
    }

    private function canManageSecurity(): bool
    {
        return $this->permissionCache['security'] ??= $this->checkPermission('manage_user_security');
    }

    private function checkPermission(string $permission): bool
    {
        $user = $this->getUser();

        return method_exists($user, 'hasElevatedPrivileges')
            ? ($user->hasElevatedPrivileges() || $user->can($permission))
            : $user->can($permission);
    }

    // =========================================================================
    // Display Helpers
    // =========================================================================

    private function formatAccountStatus(User $user): HtmlString
    {
        $status = $user->account_status?->value ?? 'active';
        $colors = [
            'active' => 'green',
            'blocked' => 'red',
            'suspended' => 'yellow',
            'terminated' => 'gray',
        ];
        $color = $colors[$status] ?? 'gray';
        $label = ucfirst($status);

        $description = match ($status) {
            'active' => __('ui.auth.profile.status.active_desc'),
            'blocked' => __('ui.auth.profile.status.blocked_desc', ['reason' => $user->blocked_reason ?? '-']),
            'suspended' => __('ui.auth.profile.status.suspended_desc'),
            'terminated' => __('ui.auth.profile.status.terminated_desc'),
            default => '',
        };

        return new HtmlString(
            "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{$color}-100 text-{$color}-800\">{$label}</span>"
            .($description ? "<p class=\"text-sm text-gray-500 mt-1\">{$description}</p>" : '')
        );
    }

    private function formatPasswordExpiry(User $user): HtmlString
    {
        if (! $user->password_expires_at) {
            return new HtmlString('<span class="text-gray-500">'.__('ui.auth.profile.values.no_expiry').'</span>');
        }

        $expires = $user->password_expires_at;
        $now = now();

        if ($expires->isPast()) {
            return new HtmlString('<span class="text-red-600 font-medium">'.__('ui.auth.profile.values.expired').'</span>');
        }

        $daysLeft = (int) $now->diffInDays($expires);
        if ($daysLeft <= 7) {
            return new HtmlString("<span class=\"text-yellow-600\">{$expires->format('d M Y')} ({$daysLeft} ".__('ui.auth.profile.values.days_left').')</span>');
        }

        return new HtmlString("<span class=\"text-gray-700\">{$expires->format('d M Y')}</span>");
    }

    // =========================================================================
    // Audit Logging
    // =========================================================================

    private function auditProfileUpdate(): void
    {
        $request = request();
        $user = $this->getUser();
        $requestId = SecurityService::requestId($request);
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        $changedFields = array_keys($user->getChanges());
        $sensitiveFields = ['password', 'email', 'two_factor_enabled'];
        $hasSensitiveChange = ! empty(array_intersect($changedFields, $sensitiveFields));

        AuditLogWriter::writeAudit([
            'user_id' => $user->getAuthIdentifier(),
            'action' => 'profile_updated',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->getAuthIdentifier(),
            'old_values' => null,
            'new_values' => ['changed_fields' => $changedFields],
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) ($request?->userAgent() ?? ''), 255),
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'source' => 'profile_page',
                'has_sensitive_change' => $hasSensitiveChange,
                'changed_fields' => $changedFields,
            ],
            'created_at' => now(),
        ]);
    }

    // =========================================================================
    // Storage Helpers
    // =========================================================================

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveAvatarUploadDisks(): array
    {
        $primary = (string) SystemSettings::getValue('storage.primary_disk', 'public');
        $fallback = (string) SystemSettings::getValue('storage.fallback_disk', 'public');

        $primary = $this->sanitizePublicDisk($primary);
        $fallback = $this->sanitizePublicDisk($fallback) ?: 'public';

        if (! $primary) {
            $primary = $fallback;
        }

        return [$primary, $fallback];
    }

    private function sanitizePublicDisk(?string $disk): ?string
    {
        if (! $disk) {
            return null;
        }

        $config = config("filesystems.disks.{$disk}");
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
    private function countryDialCodes(): array
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
}

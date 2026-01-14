<?php

namespace App\Filament\Auth\Pages;

use App\Support\SystemSettings;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class EditProfile extends BaseEditProfile
{
    public static function getLabel(): string
    {
        return __('ui.auth.profile.label');
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->getUser();
        $canIdentity = $this->canManageIdentity();
        $canAvatar = $this->canManageAvatar();
        $canSecurity = $this->canManageSecurity();
        [$disk, $fallbackDisk] = $this->resolveAvatarUploadDisks();

        return $schema->components([
            Tabs::make('Profil')
                ->persistTabInQueryString()
                ->tabs([
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
                                        ->disabled(fn (): bool => ! $canIdentity),
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
                                        ->default(fn () => $user?->locale ?: config('app.locale', 'en'))
                                        ->disabled(fn (): bool => ! $canIdentity),
                                ])
                                ->columns(2),
                        ]),
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
                                    TextInput::make('password')
                                        ->label(__('ui.auth.profile.fields.password_new'))
                                        ->password()
                                        ->revealable(filament()->arePasswordsRevealable())
                                        ->rule(Password::default())
                                        ->showAllValidationMessages()
                                        ->autocomplete('new-password')
                                        ->dehydrated(fn ($state): bool => filled($state))
                                        ->dehydrateStateUsing(fn ($state): string => Hash::make($state))
                                        ->live(debounce: 500)
                                        ->same('passwordConfirmation')
                                        ->prefixIcon('heroicon-o-key')
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
                                ->columns(2),
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
            $allowed[] = 'password';
        }

        return Arr::only($data, $allowed);
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

    private function hasEditableFields(): bool
    {
        return $this->canManageAvatar()
            || $this->canManageIdentity()
            || $this->canManageSecurity();
    }

    private function canManageAvatar(): bool
    {
        $user = $this->getUser();

        return method_exists($user, 'hasElevatedPrivileges')
            ? ($user->hasElevatedPrivileges() || $user->can('manage_user_avatar'))
            : $user->can('manage_user_avatar');
    }

    private function canManageIdentity(): bool
    {
        $user = $this->getUser();

        return method_exists($user, 'hasElevatedPrivileges')
            ? ($user->hasElevatedPrivileges() || $user->can('manage_user_identity'))
            : $user->can('manage_user_identity');
    }

    private function canManageSecurity(): bool
    {
        $user = $this->getUser();

        return method_exists($user, 'hasElevatedPrivileges')
            ? ($user->hasElevatedPrivileges() || $user->can('manage_user_security'))
            : $user->can('manage_user_security');
    }

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

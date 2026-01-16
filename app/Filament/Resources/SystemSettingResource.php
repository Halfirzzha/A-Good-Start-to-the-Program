<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemSettingResource\Pages;
use App\Models\SystemSetting;
use App\Support\AuthHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SystemSettingResource extends Resource
{
    protected static ?string $model = SystemSetting::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make(__('ui.system_settings.tabs.settings'))
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('ui.system_settings.tabs.general'))
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->schema([
                            Section::make(__('ui.system_settings.sections.project.title'))
                                ->description(__('ui.system_settings.sections.project.description'))
                                ->visible(fn (): bool => self::canViewProjectSettings())
                                ->schema([
                                    TextInput::make('project_name')
                                        ->label(__('ui.system_settings.fields.project_name'))
                                        ->prefixIcon('heroicon-o-building-office-2')
                                        ->disabled(fn (): bool => ! self::canManageProjectSettings())
                                        ->required()
                                        ->maxLength(120),
                                    TextInput::make('project_url')
                                        ->label(__('ui.system_settings.fields.project_url'))
                                        ->prefixIcon('heroicon-o-link')
                                        ->readOnly(fn (): bool => ! self::canEditProjectUrl())
                                        ->disabled(fn (): bool => ! self::canManageProjectSettings())
                                        ->afterStateHydrated(function (?string $state, Set $set): void {
                                            if (! $state) {
                                                $set('project_url', config('app.url'));
                                            }
                                        })
                                        ->maxLength(191),
                                    RichEditor::make('project_description')
                                        ->label(__('ui.system_settings.fields.project_description'))
                                        ->hintIcon('heroicon-o-document-text')
                                        ->toolbarButtons([
                                            'bold',
                                            'italic',
                                            'bulletList',
                                            'orderedList',
                                            'link',
                                        ])
                                        ->disabled(fn (): bool => ! self::canManageProjectSettings())
                                        ->maxLength(500)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make(__('ui.system_settings.tabs.branding'))
                        ->icon('heroicon-o-swatch')
                        ->schema([
                            Section::make(__('ui.system_settings.sections.logo.title'))
                                ->description(__('ui.system_settings.sections.logo.description'))
                                ->visible(fn (): bool => self::canViewBrandingSettings())
                                ->schema([
                                    Placeholder::make('logo_preview')
                                        ->label(__('ui.system_settings.fields.current_logo'))
                                        ->hintIcon('heroicon-o-photo')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'logo')),
                                    FileUpload::make('branding_logo_upload')
                                        ->label(__('ui.system_settings.fields.upload_logo'))
                                        ->hintIcon('heroicon-o-arrow-up-tray')
                                        ->image()
                                        ->imageEditor()
                                        ->disabled(fn (): bool => ! self::canManageBrandingSettings())
                                        ->storeFiles(false)
                                        ->maxSize(2048)
                                        ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                                        ->helperText(__('ui.system_settings.helpers.logo')),
                                ])
                                ->columns(2),
                            Section::make(__('ui.system_settings.sections.cover.title'))
                                ->description(__('ui.system_settings.sections.cover.description'))
                                ->visible(fn (): bool => self::canViewBrandingSettings())
                                ->schema([
                                    Placeholder::make('cover_preview')
                                        ->label(__('ui.system_settings.fields.current_cover'))
                                        ->hintIcon('heroicon-o-photo')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'cover')),
                                    FileUpload::make('branding_cover_upload')
                                        ->label(__('ui.system_settings.fields.upload_cover'))
                                        ->hintIcon('heroicon-o-arrow-up-tray')
                                        ->image()
                                        ->imageEditor()
                                        ->disabled(fn (): bool => ! self::canManageBrandingSettings())
                                        ->storeFiles(false)
                                        ->maxSize(4096)
                                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                        ->helperText(__('ui.system_settings.helpers.cover')),
                                ])
                                ->columns(2),
                            Section::make(__('ui.system_settings.sections.favicon.title'))
                                ->description(__('ui.system_settings.sections.favicon.description'))
                                ->visible(fn (): bool => self::canViewBrandingSettings())
                                ->schema([
                                    Placeholder::make('favicon_preview')
                                        ->label(__('ui.system_settings.fields.current_favicon'))
                                        ->hintIcon('heroicon-o-photo')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'favicon')),
                                    FileUpload::make('branding_favicon_upload')
                                        ->label(__('ui.system_settings.fields.upload_favicon'))
                                        ->hintIcon('heroicon-o-arrow-up-tray')
                                        ->image()
                                        ->disabled(fn (): bool => ! self::canManageBrandingSettings())
                                        ->storeFiles(false)
                                        ->maxSize(512)
                                        ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])
                                        ->helperText(__('ui.system_settings.helpers.favicon')),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make(__('ui.system_settings.tabs.storage'))
                        ->icon('heroicon-o-server-stack')
                        ->schema([
                            Section::make(__('ui.system_settings.sections.storage.title'))
                                ->description(__('ui.system_settings.sections.storage.description'))
                                ->visible(fn (): bool => self::canViewStorageSettings())
                                ->schema([
                                    Select::make('storage_primary_disk')
                                        ->label(__('ui.system_settings.fields.primary_disk'))
                                        ->prefixIcon('heroicon-o-cloud')
                                        ->options(self::storageOptions())
                                        ->native(false)
                                        ->disabled(fn (): bool => ! self::canManageStorageSettings())
                                        ->required(),
                                    Select::make('storage_fallback_disk')
                                        ->label(__('ui.system_settings.fields.fallback_disk'))
                                        ->prefixIcon('heroicon-o-arrow-path')
                                        ->options(self::storageOptions())
                                        ->native(false)
                                        ->disabled(fn (): bool => ! self::canManageStorageSettings())
                                        ->required(),
                                    TextInput::make('storage_drive_root')
                                        ->label(__('ui.system_settings.fields.drive_root'))
                                        ->prefixIcon('heroicon-o-folder')
                                        ->disabled(fn (): bool => ! self::canManageStorageSettings())
                                        ->maxLength(150)
                                        ->helperText(__('ui.system_settings.helpers.drive_root')),
                                    TextInput::make('storage_drive_folder_branding')
                                        ->label(__('ui.system_settings.fields.branding_folder'))
                                        ->prefixIcon('heroicon-o-swatch')
                                        ->disabled(fn (): bool => ! self::canManageStorageSettings())
                                        ->maxLength(150),
                                    TextInput::make('storage_drive_folder_favicon')
                                        ->label(__('ui.system_settings.fields.favicon_folder'))
                                        ->prefixIcon('heroicon-o-star')
                                        ->disabled(fn (): bool => ! self::canManageStorageSettings())
                                        ->maxLength(150),
                                ])
                                ->columns(2),
                            Section::make(__('ui.system_settings.sections.drive.title'))
                                ->description(__('ui.system_settings.sections.drive.description'))
                                ->visible(fn (): bool => self::canViewStorageSettings())
                                ->schema([
                                    Textarea::make('google_drive_service_account_json')
                                        ->label(__('ui.system_settings.fields.service_account'))
                                        ->hintIcon('heroicon-o-document-text')
                                        ->rows(5)
                                        ->visible(fn (): bool => self::canEditSecrets() && self::canManageStorageSettings())
                                        ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageStorageSettings())
                                        ->helperText(__('ui.system_settings.helpers.service_account')),
                                    TextInput::make('google_drive_client_id')
                                        ->label(__('ui.system_settings.fields.oauth_client_id'))
                                        ->prefixIcon('heroicon-o-key')
                                        ->visible(fn (): bool => self::canEditSecrets() && self::canManageStorageSettings())
                                        ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageStorageSettings())
                                        ->maxLength(191),
                                    TextInput::make('google_drive_client_secret')
                                        ->label(__('ui.system_settings.fields.oauth_client_secret'))
                                        ->prefixIcon('heroicon-o-lock-closed')
                                        ->visible(fn (): bool => self::canEditSecrets() && self::canManageStorageSettings())
                                        ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageStorageSettings())
                                        ->password()
                                        ->maxLength(191),
                                    TextInput::make('google_drive_refresh_token')
                                        ->label(__('ui.system_settings.fields.oauth_refresh_token'))
                                        ->prefixIcon('heroicon-o-arrow-path-rounded-square')
                                        ->visible(fn (): bool => self::canEditSecrets() && self::canManageStorageSettings())
                                        ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageStorageSettings())
                                        ->password()
                                        ->maxLength(191),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make(__('ui.system_settings.tabs.communication'))
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Section::make(__('ui.system_settings.sections.email.title'))
                                        ->description(__('ui.system_settings.sections.email.description'))
                                        ->visible(fn (): bool => self::canViewCommunicationSettings())
                                        ->headerActions([
                                            Action::make('check_smtp_connection')
                                                ->label(__('ui.system_settings.actions.check_smtp'))
                                                ->icon('heroicon-o-signal')
                                                ->color('secondary')
                                                ->iconButton()
                                                ->tooltip(__('ui.system_settings.actions.check_smtp'))
                                                ->visible(fn (): bool => self::canManageCommunicationSettings())
                                                ->action(function (): void {
                                                    if (! self::canManageCommunicationSettings()) {
                                                        abort(403);
                                                    }

                                                    if (self::isRateLimited('smtp_check', 6, 60)) {
                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.too_many_title'))
                                                            ->body(__('ui.system_settings.notifications.smtp_check_wait'))
                                                            ->warning()
                                                            ->send();

                                                        return;
                                                    }

                                                    $host = (string) \App\Support\SystemSettings::getValue('notifications.email.smtp_host', '');
                                                    $port = (int) \App\Support\SystemSettings::getValue('notifications.email.smtp_port', 0);
                                                    $encryption = (string) \App\Support\SystemSettings::getValue('notifications.email.smtp_encryption', '');

                                                    if ($host === '' || $port === 0) {
                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.smtp_missing_title'))
                                                            ->body(__('ui.system_settings.notifications.smtp_missing_body'))
                                                            ->warning()
                                                            ->send();

                                                        return;
                                                    }

                                                    $transportHost = $host;
                                                    if ($encryption === 'ssl') {
                                                        $transportHost = 'ssl://'.$host;
                                                    }

                                                    $timeout = 5;
                                                    $errno = 0;
                                                    $errstr = '';
                                                    $connection = @fsockopen($transportHost, $port, $errno, $errstr, $timeout);

                                                    if (is_resource($connection)) {
                                                        fclose($connection);
                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.smtp_connected_title'))
                                                            ->body(__('ui.system_settings.notifications.smtp_connected_body', ['host' => $host, 'port' => $port]))
                                                            ->success()
                                                            ->send();

                                                        return;
                                                    }

                                                    $message = $errstr !== '' ? $errstr : __('ui.system_settings.notifications.smtp_failed_body');
                                                    \Filament\Notifications\Notification::make()
                                                        ->title(__('ui.system_settings.notifications.smtp_failed_title'))
                                                        ->body($message)
                                                        ->danger()
                                                        ->send();
                                                }),
                                            Action::make('send_test_email')
                                                ->icon('heroicon-o-paper-airplane')
                                                ->color('warning')
                                                ->iconButton()
                                                ->tooltip(__('ui.system_settings.actions.send_test_email'))
                                                ->visible(fn (): bool => self::canManageCommunicationSettings())
                                                ->action(function (): void {
                                                    if (! self::canManageCommunicationSettings()) {
                                                        abort(403);
                                                    }

                                                    if (self::isRateLimited('smtp_test_email', 4, 60)) {
                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.too_many_title'))
                                                            ->body(__('ui.system_settings.notifications.test_wait'))
                                                            ->warning()
                                                            ->send();

                                                        return;
                                                    }

                                                    $recipients = \App\Support\SystemSettings::getValue('notifications.email.recipients', []);
                                                    $recipients = is_array($recipients) ? array_filter($recipients) : [];
                                                    $userEmail = AuthHelper::user()?->email;

                                                    if (empty($recipients) && $userEmail) {
                                                        $recipients = [$userEmail];
                                                    }

                                                    if (empty($recipients)) {
                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.recipients_empty_title'))
                                                            ->body(__('ui.system_settings.notifications.recipients_empty_body'))
                                                            ->danger()
                                                            ->send();

                                                        return;
                                                    }

                                                    $fromAddress = (string) \App\Support\SystemSettings::getValue('notifications.email.from_address', '');
                                                    $fromName = (string) \App\Support\SystemSettings::getValue('notifications.email.from_name', '');

                                                    $body = __('ui.system_settings.notifications.test_body');

                                                    try {
                                                        \App\Support\SystemSettings::applyMailConfig('general');
                                                        \Illuminate\Support\Facades\Mail::raw($body, function ($mail) use ($recipients, $fromAddress, $fromName): void {
                                                            $mail->to($recipients)->subject(__('ui.system_settings.notifications.test_subject'));
                                                            if ($fromAddress !== '') {
                                                                $mail->from($fromAddress, $fromName !== '' ? $fromName : null);
                                                            }
                                                        });

                                                        \App\Support\NotificationDeliveryLogger::log(
                                                            null,
                                                            null,
                                                            'mail',
                                                            'sent',
                                                            [
                                                                'notification_type' => 'test_email',
                                                                'recipient' => implode(', ', $recipients),
                                                                'summary' => __('ui.system_settings.notifications.test_delivery_summary'),
                                                                'request_id' => request()?->headers->get('X-Request-Id'),
                                                            ],
                                                        );

                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.test_sent_title'))
                                                            ->success()
                                                            ->send();
                                                    } catch (\Throwable $error) {
                                                        \App\Support\NotificationDeliveryLogger::log(
                                                            null,
                                                            null,
                                                            'mail',
                                                            'failed',
                                                            [
                                                                'notification_type' => 'test_email',
                                                                'recipient' => implode(', ', $recipients),
                                                                'summary' => __('ui.system_settings.notifications.test_delivery_summary'),
                                                                'error_message' => $error->getMessage(),
                                                                'request_id' => request()?->headers->get('X-Request-Id'),
                                                            ],
                                                        );

                                                        \Filament\Notifications\Notification::make()
                                                            ->title(__('ui.system_settings.notifications.test_failed_title'))
                                                            ->body($error->getMessage())
                                                            ->danger()
                                                            ->send();
                                                    }
                                                }),
                                            Action::make('refresh_communication')
                                                ->icon('heroicon-o-arrow-path')
                                                ->color('warning')
                                                ->iconButton()
                                                ->tooltip(__('ui.system_settings.actions.refresh'))
                                                ->action(fn () => redirect()->to(request()->fullUrl())),
                                        ])
                                        ->schema([
                                            Toggle::make('email_enabled')
                                                ->label(__('ui.system_settings.fields.email_enabled'))
                                                ->onIcon('heroicon-o-check-circle')
                                                ->offIcon('heroicon-o-x-circle')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings()),
                                            TextInput::make('email_provider')
                                                ->label(__('ui.system_settings.fields.email_provider'))
                                                ->prefixIcon('heroicon-o-at-symbol')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(100)
                                                ->helperText(__('ui.system_settings.helpers.email_provider')),
                                            TextInput::make('email_from_name')
                                                ->label(__('ui.system_settings.fields.email_from_name'))
                                                ->prefixIcon('heroicon-o-user')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(120)
                                                ->live()
                                                ->afterStateHydrated(function (?string $state, Set $set): void {
                                                    if (! $state) {
                                                        $set('email_from_name', config('app.name', 'System'));
                                                    }
                                                })
                                                ->helperText(__('ui.system_settings.helpers.email_from_name')),
                                            TextInput::make('email_from_address')
                                                ->label(__('ui.system_settings.fields.email_from_address'))
                                                ->prefixIcon('heroicon-o-envelope')
                                                ->email()
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(191)
                                                ->helperText(function (Get $get): string {
                                                    $smtp = (string) $get('smtp_username');
                                                    $from = (string) $get('email_from_address');
                                                    $smtpDomain = self::extractEmailDomain($smtp);
                                                    $fromDomain = self::extractEmailDomain($from);
                                                    if ($smtpDomain && $fromDomain && $smtpDomain !== $fromDomain) {
                                                        return __('ui.system_settings.helpers.email_domain_warning');
                                                    }

                                                    return __('ui.system_settings.helpers.email_from_address');
                                                })
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled'))
                                                ->rules([
                                                    fn (Get $get) => function (string $attribute, $value, $fail) use ($get): void {
                                                        $smtpDomain = SystemSettingResource::extractEmailDomain(
                                                            (string) $get('smtp_username')
                                                        );
                                                        $fromDomain = SystemSettingResource::extractEmailDomain((string) $value);
                                                        if ($smtpDomain && $fromDomain && $smtpDomain !== $fromDomain) {
                                                            $fail(__('ui.system_settings.helpers.email_domain_mismatch'));
                                                        }
                                                    },
                                                ]),
                                            TextInput::make('email_auth_from_name')
                                                ->label(__('ui.system_settings.fields.email_auth_from_name'))
                                                ->prefixIcon('heroicon-o-shield-check')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(120)
                                                ->live()
                                                ->afterStateHydrated(function (?string $state, Set $set): void {
                                                    if (! $state) {
                                                        $set('email_auth_from_name', config('app.name', 'System').' OTP');
                                                    }
                                                })
                                                ->helperText(__('ui.system_settings.helpers.email_auth_from_name')),
                                            TextInput::make('email_auth_from_address')
                                                ->label(__('ui.system_settings.fields.email_auth_from_address'))
                                                ->prefixIcon('heroicon-o-lock-closed')
                                                ->email()
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(191)
                                                ->helperText(function (Get $get): string {
                                                    $smtp = (string) $get('smtp_username');
                                                    $from = (string) $get('email_auth_from_address');
                                                    $smtpDomain = self::extractEmailDomain($smtp);
                                                    $fromDomain = self::extractEmailDomain($from);
                                                    if ($smtpDomain && $fromDomain && $smtpDomain !== $fromDomain) {
                                                        return __('ui.system_settings.helpers.auth_domain_warning');
                                                    }

                                                    return __('ui.system_settings.helpers.email_auth_from_address');
                                                })
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled'))
                                                ->rules([
                                                    fn (Get $get) => function (string $attribute, $value, $fail) use ($get): void {
                                                        $smtpDomain = SystemSettingResource::extractEmailDomain(
                                                            (string) $get('smtp_username')
                                                        );
                                                        $fromDomain = SystemSettingResource::extractEmailDomain((string) $value);
                                                        if ($smtpDomain && $fromDomain && $smtpDomain !== $fromDomain) {
                                                            $fail(__('ui.system_settings.helpers.auth_domain_mismatch'));
                                                        }
                                                    },
                                                ]),
                                            TagsInput::make('email_recipients')
                                                ->label(__('ui.system_settings.fields.email_recipients'))
                                                ->prefixIcon('heroicon-o-users')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->placeholder('ops@example.com')
                                                ->helperText(__('ui.system_settings.helpers.recipients'))
                                                ->afterStateHydrated(function ($state, Set $set): void {
                                                    if (empty($state)) {
                                                        $set('email_recipients', self::defaultEmailRecipients());
                                                    }
                                                })
                                                ->nestedRecursiveRules([
                                                    'string',
                                                    'email',
                                                    'max:254',
                                                ])
                                                ->columnSpanFull(),
                                        ])
                                        ->columns(2),
                                    Section::make(__('ui.system_settings.sections.smtp.title'))
                                        ->description(__('ui.system_settings.sections.smtp.description'))
                                        ->visible(fn (): bool => self::canViewCommunicationSettings())
                                        ->schema([
                                            Select::make('smtp_mailer')
                                                ->label(__('ui.system_settings.fields.smtp_mailer'))
                                                ->prefixIcon('heroicon-o-cog-6-tooth')
                                                ->options([
                                                    'smtp' => 'SMTP',
                                                ])
                                                ->native(false)
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings()),
                                            TextInput::make('smtp_host')
                                                ->label(__('ui.system_settings.fields.smtp_host'))
                                                ->prefixIcon('heroicon-o-server')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(191)
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled'))
                                                ->helperText(__('ui.system_settings.helpers.smtp_host')),
                                            TextInput::make('smtp_port')
                                                ->label(__('ui.system_settings.fields.smtp_port'))
                                                ->prefixIcon('heroicon-o-hashtag')
                                                ->numeric()
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->minValue(1)
                                                ->maxValue(65535)
                                                ->live()
                                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                                    $port = (int) $state;
                                                    if ($port === 465) {
                                                        $set('smtp_encryption', 'ssl');
                                                    } elseif ($port === 587) {
                                                        $set('smtp_encryption', 'tls');
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled'))
                                                ->helperText(__('ui.system_settings.helpers.smtp_port')),
                                            Select::make('smtp_encryption')
                                                ->label(__('ui.system_settings.fields.smtp_encryption'))
                                                ->prefixIcon('heroicon-o-shield-check')
                                                ->options([
                                                    'tls' => 'TLS',
                                                    'ssl' => 'SSL',
                                                    '' => 'None',
                                                ])
                                                ->native(false)
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->live()
                                                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                                    $port = (int) $get('smtp_port');
                                                    if ($state === 'ssl' && ($port === 0 || $port === 587)) {
                                                        $set('smtp_port', 465);
                                                    } elseif ($state === 'tls' && ($port === 0 || $port === 465)) {
                                                        $set('smtp_port', 587);
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled')),
                                            TextInput::make('smtp_username')
                                                ->label(__('ui.system_settings.fields.smtp_username'))
                                                ->prefixIcon('heroicon-o-user-circle')
                                                ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                                ->maxLength(191)
                                                ->live()
                                                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                                    $domain = self::extractEmailDomain($state);
                                                    if (! $domain) {
                                                        return;
                                                    }

                                                    $currentSender = (string) $get('email_from_address');
                                                    if ($currentSender === '' || self::extractEmailDomain($currentSender) !== $domain) {
                                                        $set('email_from_address', 'support@'.$domain);
                                                    }

                                                    $currentAuth = (string) $get('email_auth_from_address');
                                                    if ($currentAuth === '' || self::extractEmailDomain($currentAuth) !== $domain) {
                                                        $set('email_auth_from_address', 'no-reply@'.$domain);
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => (bool) $get('email_enabled')),
                                            TextInput::make('smtp_password')
                                                ->label(__('ui.system_settings.fields.smtp_password'))
                                                ->prefixIcon('heroicon-o-key')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageCommunicationSettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageCommunicationSettings())
                                                ->helperText(__('ui.system_settings.helpers.smtp_password'))
                                                ->maxLength(191),
                                        ])
                                        ->columns(2),
                                ])
                                ->columnSpanFull(),
                            Section::make(__('ui.system_settings.sections.telegram.title'))
                                ->description(__('ui.system_settings.sections.telegram.description'))
                                ->visible(fn (): bool => self::canViewCommunicationSettings())
                                ->schema([
                                    Toggle::make('telegram_enabled')
                                        ->label(__('ui.system_settings.fields.telegram_enabled'))
                                        ->onIcon('heroicon-o-check-circle')
                                        ->offIcon('heroicon-o-x-circle')
                                        ->disabled(fn (): bool => ! self::canManageCommunicationSettings()),
                                    TextInput::make('telegram_chat_id')
                                        ->label(__('ui.system_settings.fields.telegram_chat_id'))
                                        ->prefixIcon('heroicon-o-chat-bubble-left-right')
                                        ->disabled(fn (): bool => ! self::canManageCommunicationSettings())
                                        ->maxLength(50)
                                        ->required(fn (Get $get): bool => (bool) $get('telegram_enabled'))
                                        ->rules([
                                            'nullable',
                                            'regex:/^-?[0-9]+$/',
                                        ]),
                                    TextInput::make('telegram_bot_token')
                                        ->label(__('ui.system_settings.fields.telegram_bot_token'))
                                        ->prefixIcon('heroicon-o-key')
                                        ->password()
                                        ->visible(fn (): bool => self::canEditSecrets() && self::canManageCommunicationSettings())
                                        ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageCommunicationSettings())
                                        ->required(fn (Get $get): bool => self::canEditSecrets() && self::canManageCommunicationSettings() && (bool) $get('telegram_enabled'))
                                        ->maxLength(191),
                                ])
                                ->columns(3),
                        ]),
                    Tab::make(__('ui.system_settings.tabs.ai'))
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([
                            // =================================================================
                            // SECTION 1: Master AI Toggle & Legacy Configuration
                            // =================================================================
                            Section::make(__('ui.system_settings.sections.ai_config.title'))
                                ->description(__('ui.system_settings.sections.ai_config.description'))
                                ->icon('heroicon-o-cpu-chip')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->headerActions([
                                    Action::make('test_ai_connection')
                                        ->label(__('ui.system_settings.actions.test_ai'))
                                        ->icon('heroicon-o-bolt')
                                        ->color('warning')
                                        ->iconButton()
                                        ->tooltip(__('ui.system_settings.actions.test_ai'))
                                        ->visible(fn (): bool => self::canManageAISettings())
                                        ->action(function (): void {
                                            if (! self::canManageAISettings()) {
                                                abort(403);
                                            }

                                            if (self::isRateLimited('ai_test', 3, 60)) {
                                                \Filament\Notifications\Notification::make()
                                                    ->title(__('ui.system_settings.notifications.too_many_title'))
                                                    ->body(__('ui.system_settings.notifications.ai_test_wait'))
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $apiKey = (string) \App\Support\SystemSettings::getSecret('ai.api_key', '');
                                            $enabled = (bool) \App\Support\SystemSettings::getValue('ai.enabled', false);

                                            if (! $enabled || $apiKey === '') {
                                                \Filament\Notifications\Notification::make()
                                                    ->title(__('ui.system_settings.notifications.ai_not_configured_title'))
                                                    ->body(__('ui.system_settings.notifications.ai_not_configured_body'))
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                /** @var \Illuminate\Http\Client\Response $response */
                                                $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                                                    ->timeout(10)
                                                    ->get('https://api.openai.com/v1/models');

                                                if ($response->successful()) {
                                                    \Filament\Notifications\Notification::make()
                                                        ->title(__('ui.system_settings.notifications.ai_connected_title'))
                                                        ->body(__('ui.system_settings.notifications.ai_connected_body'))
                                                        ->success()
                                                        ->send();
                                                } else {
                                                    $errorMsg = $response->json('error.message', 'Connection failed');
                                                    \Filament\Notifications\Notification::make()
                                                        ->title(__('ui.system_settings.notifications.ai_failed_title'))
                                                        ->body(is_string($errorMsg) ? $errorMsg : 'Connection failed')
                                                        ->danger()
                                                        ->send();
                                                }
                                            } catch (\Throwable $e) {
                                                \Filament\Notifications\Notification::make()
                                                    ->title(__('ui.system_settings.notifications.ai_failed_title'))
                                                    ->body($e->getMessage())
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),
                                ])
                                ->schema([
                                    Toggle::make('ai_enabled')
                                        ->label(__('ui.system_settings.fields.ai_enabled'))
                                        ->helperText(__('ui.system_settings.helpers.ai_enabled'))
                                        ->onIcon('heroicon-o-cpu-chip')
                                        ->offIcon('heroicon-o-x-circle')
                                        ->onColor('success')
                                        ->offColor('danger')
                                        ->live()
                                        ->afterStateUpdated(fn ($state) => $state)
                                        ->disabled(fn (): bool => ! self::canManageAISettings())
                                        ->columnSpanFull(),
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('ai_api_key')
                                                ->label(__('ui.system_settings.fields.ai_api_key'))
                                                ->prefixIcon('heroicon-o-key')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText(__('ui.system_settings.helpers.ai_api_key'))
                                                ->maxLength(191),
                                        ])
                                        ->visible(fn (Get $get): bool => (bool) $get('ai_enabled')),
                                ])
                                ->columns(1),

                            // =================================================================
                            // SECTION 2: Multi-Provider AI (Enterprise)
                            // =================================================================
                            Section::make('Multi-Provider AI (Enterprise)')
                                ->description('Configure multiple AI providers for automatic failover. When one provider fails, the system automatically switches to the next available.')
                                ->icon('heroicon-o-server-stack')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->collapsible()
                                ->persistCollapsed()
                                ->schema([
                                    // API Keys Grid
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('groq_api_key')
                                                ->label('Groq API Key (Priority 1 - Fastest)')
                                                ->prefixIcon('heroicon-o-bolt')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText('Free tier available. Models: Llama 3.3, Mixtral. Get key at: console.groq.com')
                                                ->maxLength(191),
                                            TextInput::make('openai_api_key')
                                                ->label('OpenAI API Key (Priority 2)')
                                                ->prefixIcon('heroicon-o-sparkles')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText('GPT-4o, GPT-4o-mini. Get key at: platform.openai.com')
                                                ->maxLength(191),
                                            TextInput::make('anthropic_api_key')
                                                ->label('Anthropic API Key (Priority 3)')
                                                ->prefixIcon('heroicon-o-beaker')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText('Claude 3.5 Sonnet, Claude 3 Haiku. Get key at: console.anthropic.com')
                                                ->maxLength(191),
                                            TextInput::make('gemini_api_key')
                                                ->label('Google Gemini API Key (Priority 4)')
                                                ->prefixIcon('heroicon-o-globe-alt')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText('Gemini 2.0 Flash, 1.5 Pro. Free tier available. Get key at: aistudio.google.com')
                                                ->maxLength(191),
                                            TextInput::make('openrouter_api_key')
                                                ->label('OpenRouter API Key (Priority 5 - Multi-Model)')
                                                ->prefixIcon('heroicon-o-arrows-right-left')
                                                ->password()
                                                ->revealable()
                                                ->visible(fn (): bool => self::canEditSecrets() && self::canManageAISettings())
                                                ->disabled(fn (): bool => ! self::canEditSecrets() || ! self::canManageAISettings())
                                                ->helperText('Access 100+ models including FREE ones! Get key at: openrouter.ai')
                                                ->maxLength(191),
                                        ]),

                                    // Orchestrator Settings
                                    Grid::make(3)
                                        ->schema([
                                            Toggle::make('ai_failover_enabled')
                                                ->label('Enable Automatic Failover')
                                                ->helperText('Automatically switch to next provider when one fails')
                                                ->onIcon('heroicon-o-arrow-path')
                                                ->onColor('success')
                                                ->offColor('gray')
                                                ->default(true)
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_smart_selection')
                                                ->label('Smart Provider Selection')
                                                ->helperText('Remember last successful provider for faster responses')
                                                ->onIcon('heroicon-o-light-bulb')
                                                ->onColor('success')
                                                ->offColor('gray')
                                                ->default(true)
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_daily_limit')
                                                ->label('Daily Cost Limit (USD)')
                                                ->prefixIcon('heroicon-o-currency-dollar')
                                                ->numeric()
                                                ->step(0.01)
                                                ->minValue(0.1)
                                                ->maxValue(1000)
                                                ->default(10.00)
                                                ->helperText('Maximum daily AI spending across all providers')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                        ]),
                                ]),

                            // =================================================================
                            // SECTION 3: AI Rate Limiting
                            // =================================================================
                            Section::make(__('ui.system_settings.sections.ai_rate_limit.title'))
                                ->description(__('ui.system_settings.sections.ai_rate_limit.description'))
                                ->icon('heroicon-o-clock')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->collapsible()
                                ->collapsed()
                                ->persistCollapsed()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('ai_rate_limit_rpm')
                                                ->label(__('ui.system_settings.fields.ai_rate_limit_rpm'))
                                                ->prefixIcon('heroicon-o-clock')
                                                ->numeric()
                                                ->suffix('req/min')
                                                ->minValue(1)
                                                ->maxValue(10000)
                                                ->helperText('Maximum API requests per minute. Prevents API abuse and controls costs.')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_rate_limit_tpm')
                                                ->label(__('ui.system_settings.fields.ai_rate_limit_tpm'))
                                                ->prefixIcon('heroicon-o-calculator')
                                                ->numeric()
                                                ->suffix('tokens/min')
                                                ->minValue(1000)
                                                ->maxValue(10000000)
                                                ->helperText('Maximum tokens processed per minute. Higher = more throughput, but higher costs.')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_rate_limit_tpd')
                                                ->label(__('ui.system_settings.fields.ai_rate_limit_tpd'))
                                                ->prefixIcon('heroicon-o-calendar')
                                                ->numeric()
                                                ->suffix('tokens/day')
                                                ->minValue(10000)
                                                ->maxValue(100000000)
                                                ->helperText('Daily token budget. When reached, AI features pause until reset at midnight UTC.')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_usage_display')
                                                ->label(__('ui.system_settings.fields.ai_usage_today'))
                                                ->prefixIcon('heroicon-o-chart-bar')
                                                ->suffix(fn (?SystemSetting $record): string => $record ? round((int) $record->ai_daily_usage / max((int) ($record->ai_rate_limit_tpd ?? 1000000), 1) * 100, 1).'%' : '0%')
                                                ->default(fn (?SystemSetting $record): string => $record ? number_format((int) $record->ai_daily_usage).' / '.number_format((int) ($record->ai_rate_limit_tpd ?? 1000000)).' tokens' : '0 / 1,000,000 tokens')
                                                ->disabled()
                                                ->helperText('Current token usage today. Resets at midnight UTC automatically.'),
                                        ]),
                                ]),

                            // =================================================================
                            // SECTION 4: AI Features (All Toggles with Live)
                            // =================================================================
                            Section::make(__('ui.system_settings.sections.ai_features.title'))
                                ->description(__('ui.system_settings.sections.ai_features.description'))
                                ->icon('heroicon-o-squares-plus')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->collapsible()
                                ->persistCollapsed()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            Toggle::make('ai_feature_security_analysis')
                                                ->label(__('ui.system_settings.fields.ai_feature_security_analysis'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_security_analysis'))
                                                ->onIcon('heroicon-o-shield-check')
                                                ->onColor('success')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_anomaly_detection')
                                                ->label(__('ui.system_settings.fields.ai_feature_anomaly_detection'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_anomaly_detection'))
                                                ->onIcon('heroicon-o-exclamation-triangle')
                                                ->onColor('warning')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_threat_classification')
                                                ->label(__('ui.system_settings.fields.ai_feature_threat_classification'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_threat_classification'))
                                                ->onIcon('heroicon-o-bug-ant')
                                                ->onColor('danger')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_log_summarization')
                                                ->label(__('ui.system_settings.fields.ai_feature_log_summarization'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_log_summarization'))
                                                ->onIcon('heroicon-o-document-text')
                                                ->onColor('info')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_smart_alerts')
                                                ->label(__('ui.system_settings.fields.ai_feature_smart_alerts'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_smart_alerts'))
                                                ->onIcon('heroicon-o-bell-alert')
                                                ->onColor('primary')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_auto_response')
                                                ->label(__('ui.system_settings.fields.ai_feature_auto_response'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_auto_response'))
                                                ->onIcon('heroicon-o-bolt')
                                                ->onColor('warning')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_feature_chat_assistant')
                                                ->label(__('ui.system_settings.fields.ai_feature_chat_assistant'))
                                                ->helperText(__('ui.system_settings.helpers.ai_feature_chat_assistant'))
                                                ->onIcon('heroicon-o-chat-bubble-bottom-center-text')
                                                ->onColor('success')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                        ]),
                                ]),

                            // =================================================================
                            // SECTION 5: AI Alert Thresholds
                            // =================================================================
                            Section::make(__('ui.system_settings.sections.ai_alerts.title'))
                                ->description(__('ui.system_settings.sections.ai_alerts.description'))
                                ->icon('heroicon-o-bell')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->collapsible()
                                ->collapsed()
                                ->persistCollapsed()
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            TextInput::make('ai_alert_high_risk_score')
                                                ->label(__('ui.system_settings.fields.ai_alert_high_risk_score'))
                                                ->prefixIcon('heroicon-o-exclamation-circle')
                                                ->numeric()
                                                ->minValue(1)
                                                ->maxValue(10)
                                                ->disabled(fn (): bool => ! self::canManageAISettings())
                                                ->helperText(__('ui.system_settings.helpers.ai_alert_high_risk_score')),
                                            TextInput::make('ai_alert_suspicious_patterns')
                                                ->label(__('ui.system_settings.fields.ai_alert_suspicious_patterns'))
                                                ->prefixIcon('heroicon-o-eye')
                                                ->numeric()
                                                ->minValue(1)
                                                ->maxValue(50)
                                                ->helperText('Minimum suspicious patterns detected before triggering an alert.')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_alert_failed_logins')
                                                ->label(__('ui.system_settings.fields.ai_alert_failed_logins'))
                                                ->prefixIcon('heroicon-o-lock-closed')
                                                ->numeric()
                                                ->minValue(1)
                                                ->maxValue(100)
                                                ->helperText('Number of failed login attempts before AI flags the account.')
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            TextInput::make('ai_alert_anomaly_confidence')
                                                ->label(__('ui.system_settings.fields.ai_alert_anomaly_confidence'))
                                                ->prefixIcon('heroicon-o-chart-bar')
                                                ->numeric()
                                                ->step(0.01)
                                                ->minValue(0.5)
                                                ->maxValue(1.0)
                                                ->disabled(fn (): bool => ! self::canManageAISettings())
                                                ->helperText(__('ui.system_settings.helpers.ai_alert_anomaly_confidence')),
                                        ]),
                                ]),

                            // =================================================================
                            // SECTION 6: AI Automated Actions
                            // =================================================================
                            Section::make(__('ui.system_settings.sections.ai_actions.title'))
                                ->description(__('ui.system_settings.sections.ai_actions.description'))
                                ->icon('heroicon-o-cog-6-tooth')
                                ->visible(fn (): bool => self::canViewAISettings())
                                ->collapsible()
                                ->collapsed()
                                ->persistCollapsed()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            Toggle::make('ai_action_auto_block_ip')
                                                ->label(__('ui.system_settings.fields.ai_action_auto_block_ip'))
                                                ->helperText(__('ui.system_settings.helpers.ai_action_auto_block_ip'))
                                                ->onIcon('heroicon-o-no-symbol')
                                                ->onColor('danger')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_action_auto_lock_user')
                                                ->label(__('ui.system_settings.fields.ai_action_auto_lock_user'))
                                                ->helperText(__('ui.system_settings.helpers.ai_action_auto_lock_user'))
                                                ->onIcon('heroicon-o-lock-closed')
                                                ->onColor('danger')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_action_notify_admin')
                                                ->label(__('ui.system_settings.fields.ai_action_notify_admin'))
                                                ->helperText(__('ui.system_settings.helpers.ai_action_notify_admin'))
                                                ->onIcon('heroicon-o-bell')
                                                ->onColor('warning')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                            Toggle::make('ai_action_create_incident')
                                                ->label(__('ui.system_settings.fields.ai_action_create_incident'))
                                                ->helperText(__('ui.system_settings.helpers.ai_action_create_incident'))
                                                ->onIcon('heroicon-o-clipboard-document-list')
                                                ->onColor('info')
                                                ->offColor('gray')
                                                ->live()
                                                ->afterStateUpdated(fn ($state) => $state)
                                                ->disabled(fn (): bool => ! self::canManageAISettings()),
                                        ]),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('email_alerts')
                    ->label(__('ui.system_settings.table.email_alerts'))
                    ->boolean()
                    ->getStateUsing(fn (SystemSetting $record): bool => (bool) $record->email_enabled)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('ai_status')
                    ->label(__('ui.system_settings.table.ai_status'))
                    ->boolean()
                    ->getStateUsing(fn (SystemSetting $record): bool => (bool) $record->ai_enabled)
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('project')
                    ->label(__('ui.system_settings.table.project'))
                    ->getStateUsing(fn (SystemSetting $record): ?string => $record->project_name),
                TextColumn::make('updated_at')
                    ->label(__('ui.system_settings.table.updated'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->label(__('ui.system_settings.table.updated_by'))
                    ->getStateUsing(fn (SystemSetting $record): ?string => optional($record->updatedBy)->name ?? null),
            ])
            ->striped()
            ->paginated(false)
            ->emptyStateHeading(__('ui.system_settings.empty.heading'))
            ->emptyStateDescription(__('ui.system_settings.empty.description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('ui.system_settings.actions.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
            ]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemSettings::route('/'),
            'edit' => Pages\EditSystemSetting::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return self::canViewSettings();
    }

    public static function canView(Model $record): bool
    {
        return self::canViewSettings();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewSettings();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function assetPreview(?SystemSetting $record, string $key): HtmlString
    {
        if (! $record) {
            return new HtmlString('<span class="text-sm text-gray-500">'.__('ui.system_settings.asset.none').'</span>');
        }

        $asset = [
            'disk' => $record->getAttribute("branding_{$key}_disk"),
            'path' => $record->getAttribute("branding_{$key}_path"),
            'fallback_disk' => $record->getAttribute("branding_{$key}_fallback_disk"),
            'fallback_path' => $record->getAttribute("branding_{$key}_fallback_path"),
        ];

        $url = self::assetUrlFromMeta($asset);

        if (! $url) {
            return new HtmlString('<span class="text-sm text-gray-500">'.__('ui.system_settings.asset.no_preview').'</span>');
        }

        $safeUrl = e($url);
        $label = Str::title(str_replace('_', ' ', $key));

        return new HtmlString('<img src="'.$safeUrl.'" alt="'.$label.'" style="max-height: 120px;" loading="lazy" />');
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private static function assetUrlFromMeta(array $asset): ?string
    {
        $url = self::diskUrl($asset['disk'] ?? null, $asset['path'] ?? null);
        if ($url) {
            return $url;
        }

        return self::diskUrl($asset['fallback_disk'] ?? null, $asset['fallback_path'] ?? null);
    }

    private static function diskUrl(?string $disk, ?string $path): ?string
    {
        if (! $disk || ! $path) {
            return null;
        }

        try {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $filesystem */
            $filesystem = Storage::disk($disk);
            $url = method_exists($filesystem, 'url') ? $filesystem->url($path) : null;
        } catch (\Throwable) {
            return null;
        }

        return $url ?: null;
    }

    /**
     * @return array<string, string>
     */
    private static function storageOptions(): array
    {
        return [
            'google' => __('ui.system_settings.storage_options.google'),
            'public' => __('ui.system_settings.storage_options.public'),
            'local' => __('ui.system_settings.storage_options.local'),
        ];
    }

    private static function canEditSecrets(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_setting_secrets')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings');
    }

    private static function canEditProjectUrl(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_setting_project_url')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings');
    }

    public static function canViewProjectSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_project')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    public static function canViewBrandingSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_branding')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    public static function canViewStorageSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_storage')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    public static function canViewCommunicationSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_communication')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    public static function canViewSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('view_any_system_setting')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_settings')
            || $user->can('view_system_settings');
    }

    public static function canUpdateSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('update_system_setting')
            || $user->can('update_system_settings');
    }

    public static function canManageProjectSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_project')
            || self::canUpdateSettings();
    }

    public static function canManageBrandingSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_branding')
            || self::canUpdateSettings();
    }

    public static function canManageStorageSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_storage')
            || self::canUpdateSettings();
    }

    public static function canManageCommunicationSettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_communication')
            || self::canUpdateSettings();
    }

    public static function canViewAISettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_ai')
            || $user->can('view_system_setting_ai')
            || $user->can('update_system_setting')
            || $user->can('update_system_settings')
            || $user->can('view_system_setting')
            || $user->can('view_any_system_setting');
    }

    public static function canManageAISettings(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_system_settings_ai')
            || self::canUpdateSettings();
    }

    /**
     * @return array<int, string>
     */
    private static function defaultEmailRecipients(): array
    {
        $recipients = [];
        $currentEmail = AuthHelper::user()?->email;
        if (is_string($currentEmail) && $currentEmail !== '') {
            $recipients[] = $currentEmail;
        }

        $fallback = config('security.threat_detection.alert.emails', []);
        if (is_array($fallback)) {
            foreach ($fallback as $email) {
                if (is_string($email) && $email !== '' && ! in_array($email, $recipients, true)) {
                    $recipients[] = $email;
                }
            }
        }

        return $recipients;
    }

    private static function extractEmailDomain(?string $email): ?string
    {
        if (! is_string($email) || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $domain = trim($domain);

        return $domain !== '' ? strtolower($domain) : null;
    }

    private static function isRateLimited(string $key, int $maxAttempts, int $seconds): bool
    {
        $userId = AuthHelper::id() ?: 'guest';
        $cacheKey = "rate:system_settings:{$key}:{$userId}";

        $attempts = Cache::increment($cacheKey);
        if ($attempts === 1) {
            Cache::put($cacheKey, 1, now()->addSeconds($seconds));
        }

        return $attempts > $maxAttempts;
    }
}

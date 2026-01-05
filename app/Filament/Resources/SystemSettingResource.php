<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemSettingResource\Pages;
use App\Filament\Resources\SystemSettingResource\RelationManagers\VersionsRelationManager;
use App\Models\SystemSetting;
use App\Support\MaintenanceService;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Validation\Rule as ValidationRuleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class SystemSettingResource extends Resource
{
    protected static ?string $model = SystemSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('System Settings')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('General')
                        ->schema([
                            Section::make('Project')
                                ->schema([
                                    TextInput::make('data.project.name')
                                        ->label('Project Name')
                                        ->required()
                                        ->maxLength(120),
                                    Textarea::make('data.project.description')
                                        ->label('App Description')
                                        ->rows(3)
                                        ->maxLength(500),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Branding')
                        ->schema([
                            Section::make('Logo')
                                ->schema([
                                    Placeholder::make('logo_preview')
                                        ->label('Current Logo')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'logo')),
                                    FileUpload::make('branding_logo_upload')
                                        ->label('Upload Logo')
                                        ->image()
                                        ->imageEditor()
                                        ->storeFiles(false)
                                        ->maxSize(2048)
                                        ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                                        ->helperText('PNG/SVG recommended. Stored with automatic Drive fallback.'),
                                ])
                                ->columns(2),
                            Section::make('Cover')
                                ->schema([
                                    Placeholder::make('cover_preview')
                                        ->label('Current Cover')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'cover')),
                                    FileUpload::make('branding_cover_upload')
                                        ->label('Upload Cover')
                                        ->image()
                                        ->imageEditor()
                                        ->storeFiles(false)
                                        ->maxSize(4096)
                                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                        ->helperText('Wide image for landing or system pages.'),
                                ])
                                ->columns(2),
                            Section::make('Favicon')
                                ->schema([
                                    Placeholder::make('favicon_preview')
                                        ->label('Current Favicon')
                                        ->content(fn (?SystemSetting $record): HtmlString => self::assetPreview($record, 'favicon')),
                                    FileUpload::make('branding_favicon_upload')
                                        ->label('Upload Favicon')
                                        ->image()
                                        ->storeFiles(false)
                                        ->maxSize(512)
                                        ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])
                                        ->helperText('Square icon (PNG/ICO).'),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Storage')
                        ->schema([
                            Section::make('Storage Routing')
                                ->schema([
                                    Select::make('data.storage.primary_disk')
                                        ->label('Primary Disk')
                                        ->options(self::storageOptions())
                                        ->native(false)
                                        ->required(),
                                    Select::make('data.storage.fallback_disk')
                                        ->label('Fallback Disk')
                                        ->options(self::storageOptions())
                                        ->native(false)
                                        ->required(),
                                    TextInput::make('data.storage.drive_root')
                                        ->label('Drive Root Folder')
                                        ->maxLength(150)
                                        ->helperText('Base folder name on Google Drive.'),
                                    TextInput::make('data.storage.drive_folder_branding')
                                        ->label('Branding Folder')
                                        ->maxLength(150),
                                    TextInput::make('data.storage.drive_folder_favicon')
                                        ->label('Favicon Folder')
                                        ->maxLength(150),
                                ])
                                ->columns(2),
                            Section::make('Google Drive Credentials')
                                ->schema([
                                    Textarea::make('secrets.google_drive.service_account_json')
                                        ->label('Service Account JSON')
                                        ->rows(5)
                                        ->visible(fn (): bool => self::canEditSecrets())
                                        ->helperText('Paste JSON or provide a file path via env.'),
                                    TextInput::make('secrets.google_drive.client_id')
                                        ->label('OAuth Client ID')
                                        ->visible(fn (): bool => self::canEditSecrets())
                                        ->maxLength(191),
                                    TextInput::make('secrets.google_drive.client_secret')
                                        ->label('OAuth Client Secret')
                                        ->visible(fn (): bool => self::canEditSecrets())
                                        ->password()
                                        ->maxLength(191),
                                    TextInput::make('secrets.google_drive.refresh_token')
                                        ->label('OAuth Refresh Token')
                                        ->visible(fn (): bool => self::canEditSecrets())
                                        ->password()
                                        ->maxLength(191),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Maintenance')
                        ->schema([
                            Section::make('Maintenance Mode')
                                ->schema([
                                    Placeholder::make('maintenance_status')
                                        ->label('Status')
                                        ->content(fn (): HtmlString => self::maintenanceStatusPreview())
                                        ->columnSpanFull(),
                                    Toggle::make('data.maintenance.enabled')
                                        ->label('Aktifkan maintenance manual')
                                        ->helperText('Gunakan ini untuk memaksa mode maintenance tanpa menunggu jadwal.'),
                                    Select::make('data.maintenance.mode')
                                        ->label('Mode akses')
                                        ->options([
                                            'global' => 'Global (blokir semua akses)',
                                            'allowlist' => 'Allowlist (hanya path yang diizinkan)',
                                            'denylist' => 'Denylist (blokir path tertentu)',
                                        ])
                                        ->native(false)
                                        ->required(),
                                ])
                                ->columns(2),
                            Section::make('Jadwal Maintenance')
                                ->schema([
                                    DateTimePicker::make('data.maintenance.start_at')
                                        ->label('Maintenance Start (UTC)')
                                        ->seconds(false)
                                        ->timezone('UTC')
                                        ->helperText('Waktu UTC. Sistem akan otomatis aktif saat waktu ini tercapai.')
                                        ->rules(['nullable', 'date'])
                                        ->required(fn (Get $get): bool => (bool) $get('data.maintenance.end_at')),
                                    DateTimePicker::make('data.maintenance.end_at')
                                        ->label('Maintenance End (UTC)')
                                        ->seconds(false)
                                        ->timezone('UTC')
                                        ->helperText('Waktu UTC. Sistem akan otomatis nonaktif setelah waktu ini.')
                                        ->rules(fn (Get $get): array => $get('data.maintenance.start_at')
                                            ? ['required', 'date', 'after_or_equal:data.maintenance.start_at']
                                            : ['nullable', 'date']),
                                ])
                                ->columns(2),
                            Section::make('Pesan Publik')
                                ->schema([
                                    TextInput::make('data.maintenance.title')
                                        ->label('Judul')
                                        ->maxLength(150),
                                    Textarea::make('data.maintenance.summary')
                                        ->label('Ringkasan')
                                        ->rows(3)
                                        ->maxLength(500),
                                    RichEditor::make('data.maintenance.note_html')
                                        ->label('Operator Note (Rich)')
                                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                                        ->helperText('Konten ini tampil di halaman publik maintenance.')
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make('Access Controls')
                                ->schema([
                                    TagsInput::make('data.maintenance.allow_ips')
                                        ->label('Allow IPs')
                                        ->placeholder('203.0.113.10 or 203.0.113.0/24')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'max:64',
                                            self::ipOrCidrRule(),
                                        ]),
                                    Select::make('data.maintenance.allow_roles')
                                        ->label('Allow Roles')
                                        ->options(fn (): array => Role::query()->pluck('name', 'name')->all())
                                        ->multiple()
                                        ->native(false),
                                    Toggle::make('data.maintenance.allow_developer_bypass')
                                        ->label('Allow Developer Bypass'),
                                    Toggle::make('data.maintenance.allow_api')
                                        ->label('Allow API Requests'),
                                    TagsInput::make('data.maintenance.allow_paths')
                                        ->label('Allow Paths')
                                        ->placeholder('/admin, /status')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'max:160',
                                            self::pathPatternRule(),
                                        ]),
                                    TagsInput::make('data.maintenance.deny_paths')
                                        ->label('Deny Paths')
                                        ->placeholder('/checkout, /orders')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'max:160',
                                            self::pathPatternRule(),
                                        ]),
                                    TagsInput::make('data.maintenance.allow_routes')
                                        ->label('Allow Route Names')
                                        ->placeholder('filament.admin.pages.dashboard')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'max:160',
                                            self::routePatternRule(),
                                        ]),
                                    TagsInput::make('data.maintenance.deny_routes')
                                        ->label('Deny Route Names')
                                        ->placeholder('orders.create')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'max:160',
                                            self::routePatternRule(),
                                        ]),
                                ])
                                ->columns(2),
                            Section::make('Bypass Tokens')
                                ->schema([
                                    Placeholder::make('maintenance_token_manage')
                                        ->label('Kelola token')
                                        ->content(new HtmlString('Gunakan menu <a class="text-primary-600 underline" href="' . e(route('filament.admin.resources.maintenance-tokens.index')) . '">Maintenance Tokens</a> untuk membuat, rotasi, dan mencabut token akses.')),
                                ])
                                ->columns(1),
                        ]),
                    Tab::make('Notifications')
                        ->schema([
                            Section::make('Email Alerts')
                                ->schema([
                                    Toggle::make('data.notifications.email.enabled')
                                        ->label('Enable Email Notifications'),
                                    TagsInput::make('data.notifications.email.recipients')
                                        ->label('Recipients')
                                        ->placeholder('ops@example.com')
                                        ->helperText('Comma or enter-separated emails.')
                                        ->nestedRecursiveRules([
                                            'string',
                                            'email',
                                            'max:254',
                                        ]),
                                ]),
                            Section::make('Telegram Alerts')
                                ->schema([
                                    Toggle::make('data.notifications.telegram.enabled')
                                        ->label('Enable Telegram Alerts'),
                                    TextInput::make('data.notifications.telegram.chat_id')
                                        ->label('Chat ID')
                                        ->maxLength(50)
                                        ->required(fn (Get $get): bool => (bool) $get('data.notifications.telegram.enabled'))
                                        ->rules([
                                            'nullable',
                                            'regex:/^-?[0-9]+$/',
                                        ]),
                                    TextInput::make('secrets.telegram.bot_token')
                                        ->label('Bot Token')
                                        ->password()
                                        ->visible(fn (): bool => self::canEditSecrets())
                                        ->required(fn (Get $get): bool => self::canEditSecrets() && (bool) $get('data.notifications.telegram.enabled'))
                                        ->maxLength(191),
                                ])
                                ->columns(2),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project')
                    ->label('Project')
                    ->getStateUsing(fn (SystemSetting $record): ?string => Arr::get($record->data, 'project.name')),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->label('Updated By')
                    ->getStateUsing(fn (SystemSetting $record): ?string => optional($record->updatedBy)->name ?? null),
            ])
            ->paginated(false)
            ->emptyStateHeading('Konfigurasi sistem belum tersedia')
            ->emptyStateDescription('Initialize pengaturan sistem melalui proses provisioning agar opsi branding, maintenance, dan storage siap digunakan.')
            ->emptyStateActions([
                Action::make('refresh')
                    ->label('Segarkan')
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
            VersionsRelationManager::class,
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

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function assetPreview(?SystemSetting $record, string $key): HtmlString
    {
        if (! $record) {
            return new HtmlString('<span class="text-sm text-gray-500">Belum ada</span>');
        }

        $asset = Arr::get($record->data, 'branding.'.$key, []);
        if (! is_array($asset)) {
            return new HtmlString('<span class="text-sm text-gray-500">Belum ada</span>');
        }

        $url = self::assetUrlFromMeta($asset);

        if (! $url) {
            return new HtmlString('<span class="text-sm text-gray-500">Preview tidak tersedia</span>');
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
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }

        return $url ?: null;
    }

    private static function maintenanceStatusPreview(): HtmlString
    {
        $settings = SystemSettings::get(true);
        $maintenance = Arr::get($settings, 'data.maintenance', []);

        $snapshot = MaintenanceService::snapshot($maintenance);
        $startAt = $snapshot['start_at'];
        $endAt = $snapshot['end_at'];
        $timezone = config('app.timezone', 'UTC');

        $windowParts = [];
        if ($startAt) {
            $windowParts[] = 'start: '.$startAt->copy()->timezone($timezone)->format('Y-m-d H:i:s').' '.$timezone;
        }
        if ($endAt) {
            $windowParts[] = 'end: '.$endAt->copy()->timezone($timezone)->format('Y-m-d H:i:s').' '.$timezone;
        }

        $windowText = $windowParts !== [] ? implode(' · ', $windowParts) : 'Belum dijadwalkan';
        $statusClass = match ($snapshot['status_label']) {
            'Active' => 'text-red-600',
            'Scheduled' => 'text-amber-600',
            'Ended' => 'text-slate-500',
            default => 'text-emerald-600',
        };

        $now = now();
        $nextText = 'Tidak ada perubahan terjadwal.';
        if ($snapshot['is_scheduled'] && $startAt) {
            $nextText = self::formatRelative($startAt, $now, 'Mulai dalam', 'Dimulai');
        } elseif ($snapshot['is_active'] && $endAt) {
            $nextText = self::formatRelative($endAt, $now, 'Selesai dalam', 'Selesai');
        }

        $retryText = '—';
        if (is_int($snapshot['retry_after'])) {
            $readable = self::formatReadableDuration($snapshot['retry_after']);
            $retryText = $readable ? $readable.' ('.$snapshot['retry_after'].' detik)' : $retryText;
        }

        $statusLabel = e($snapshot['status_label']);

        return new HtmlString(
            '<div class="space-y-1 text-sm">'.
            '<div><span class="text-slate-500">Status:</span> <span class="'.$statusClass.'" data-maintenance-admin-status>'.$statusLabel.'</span></div>'.
            '<div class="text-xs text-slate-500" data-maintenance-admin-window>'.e($windowText).'</div>'.
            '<div class="text-xs text-slate-500" data-maintenance-admin-next>'.e($nextText).'</div>'.
            '<div class="text-xs text-slate-500">Retry after: <span data-maintenance-admin-retry>'.e($retryText).'</span></div>'.
            '</div>',
        );
    }

    private static function formatRelative(?Carbon $target, Carbon $now, string $prefixFuture, string $prefixPast): string
    {
        if (! $target) {
            return '—';
        }

        $seconds = $now->diffInSeconds($target, false);
        $readable = self::formatReadableDuration(abs($seconds));
        if (! $readable) {
            return '—';
        }

        return ($seconds >= 0 ? $prefixFuture : $prefixPast).' '.$readable;
    }

    private static function formatReadableDuration(int $seconds): ?string
    {
        if ($seconds < 0) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' hari';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours.' jam';
        }
        $parts[] = $minutes.' menit';

        return implode(' ', $parts);
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        return MaintenanceService::parseDate($value);
    }

    public static function isValidIpOrCidr(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$subnet, $mask] = array_pad(explode('/', $value, 2), 2, null);
        if (! $subnet || ! is_numeric($mask)) {
            return false;
        }

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = @inet_pton($subnet);
        if ($packed === false) {
            return false;
        }

        $maxBits = strlen($packed) * 8;
        $mask = (int) $mask;

        return $mask >= 0 && $mask <= $maxBits;
    }

    public static function isValidPathPattern(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if ($value === '/') {
            return true;
        }

        return (bool) preg_match('/^\\/?[A-Za-z0-9\\-._~%\\/\\*{}]+$/', $value);
    }

    public static function isValidRoutePattern(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._\\-*]+$/', $value);
    }

    private static function ipOrCidrRule(): ValidationRuleContract
    {
        return new class implements ValidationRuleContract
        {
            public function passes($attribute, $value): bool
            {
                return SystemSettingResource::isValidIpOrCidr($value);
            }

            public function message(): string
            {
                return 'IP or CIDR format is invalid.';
            }
        };
    }

    private static function pathPatternRule(): ValidationRuleContract
    {
        return new class implements ValidationRuleContract
        {
            public function passes($attribute, $value): bool
            {
                return SystemSettingResource::isValidPathPattern($value);
            }

            public function message(): string
            {
                return 'Path pattern is invalid.';
            }
        };
    }

    private static function routePatternRule(): ValidationRuleContract
    {
        return new class implements ValidationRuleContract
        {
            public function passes($attribute, $value): bool
            {
                return SystemSettingResource::isValidRoutePattern($value);
            }

            public function message(): string
            {
                return 'Route pattern is invalid.';
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function storageOptions(): array
    {
        return [
            'google' => 'Google Drive',
            'public' => 'Local Public',
            'local' => 'Local Private',
        ];
    }

    private static function canEditSecrets(): bool
    {
        $user = auth()->user();

        return $user && method_exists($user, 'isDeveloper') && $user->isDeveloper();
    }
}

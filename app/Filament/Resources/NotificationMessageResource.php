<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationMessageResource\Pages;
use App\Models\NotificationMessage;
use App\Models\NotificationTarget;
use App\Support\AIService;
use App\Support\AuthHelper;
use App\Support\NotificationCenterService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ->headerActions([
                    Action::make('auto_fill')
                        ->label(__('notifications.ui.center.actions.auto_fill'))
                        ->icon('heroicon-o-sparkles')
                        ->iconButton()
                        ->tooltip(__('notifications.ui.center.actions.auto_fill_tooltip'))
                        ->action(function (Get $get, Set $set): void {
                            $category = (string) $get('category');
                            $priority = (string) ($get('priority') ?? 'normal');
                            $language = app()->getLocale();

                            // Use current title as context if available
                            $context = (string) $get('title');

                            // Try AI first
                            $aiService = new AIService;
                            if ($aiService->isEnabled()) {
                                $aiContent = $aiService->generateNotificationContent(
                                    $category ?: 'announcement',
                                    $priority,
                                    $context,
                                    $language
                                );

                                if ($aiContent) {
                                    $set('title', $aiContent['title']);
                                    $set('message', $aiContent['message']);

                                    // Show usage stats
                                    $stats = $aiService->getUsageStats();
                                    $usageInfo = $stats['percentage'] > 0
                                        ? ' (' . $stats['percentage'] . '% ' . __('notifications.ui.center.actions.daily_usage') . ')'
                                        : '';

                                    Notification::make()
                                        ->title(__('notifications.ui.center.actions.ai_generated'))
                                        ->body(__('notifications.ui.center.actions.ai_generated_body') . $usageInfo)
                                        ->success()
                                        ->send();

                                    return;
                                }

                                // AI failed, show warning
                                Notification::make()
                                    ->title(__('notifications.ui.center.actions.ai_fallback'))
                                    ->body(__('notifications.ui.center.actions.ai_fallback_body'))
                                    ->warning()
                                    ->send();
                            }

                            // Fallback to template
                            $template = self::getNotificationTemplate($category ?: 'announcement', $priority, $language);
                            $set('title', $template['title']);
                            $set('message', $template['message']);

                            Notification::make()
                                ->title(__('notifications.ui.center.actions.template_used'))
                                ->body(__('notifications.ui.center.actions.template_used_body'))
                                ->info()
                                ->send();
                        })
                        ->visible(fn (): bool => self::canCreate()),
                ])
                ->schema([
                    Select::make('category')
                        ->label(__('notifications.ui.center.fields.category'))
                        ->options(NotificationCenterService::categoryOptions())
                        ->native(false)
                        ->required()
                        ->live(),
                    Select::make('priority')
                        ->label(__('notifications.ui.center.fields.priority'))
                        ->options(NotificationCenterService::priorityOptions())
                        ->native(false)
                        ->required()
                        ->default('normal')
                        ->live(),
                    TextInput::make('title')
                        ->label(__('notifications.ui.center.fields.title'))
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),
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
            ->poll('30s')
            ->deferLoading()
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

    /**
     * Get notification template as fallback when AI is not available.
     *
     * @return array{title: string, message: string}
     */
    private static function getNotificationTemplate(string $category, string $priority, string $language): array
    {
        $isId = $language === 'id';

        $templates = [
            'maintenance' => [
                'normal' => [
                    'title' => $isId ? 'Jadwal Pemeliharaan Sistem' : 'Scheduled System Maintenance',
                    'message' => $isId
                        ? '<p>Sistem akan menjalani pemeliharaan terjadwal untuk meningkatkan performa dan keamanan.</p><p>Selama periode ini, beberapa layanan mungkin tidak tersedia. Kami akan memberitahu Anda setelah pemeliharaan selesai.</p>'
                        : '<p>The system will undergo scheduled maintenance to improve performance and security.</p><p>During this period, some services may be temporarily unavailable. We will notify you once maintenance is complete.</p>',
                ],
                'high' => [
                    'title' => $isId ? 'Pemberitahuan Pemeliharaan Mendesak' : 'Urgent Maintenance Notice',
                    'message' => $isId
                        ? '<p><strong>Perhatian:</strong> Sistem memerlukan pemeliharaan mendesak.</p><p>Mohon simpan pekerjaan Anda segera. Layanan akan terganggu dalam waktu dekat.</p>'
                        : '<p><strong>Attention:</strong> The system requires urgent maintenance.</p><p>Please save your work immediately. Services will be interrupted shortly.</p>',
                ],
                'critical' => [
                    'title' => $isId ? 'KRITIS: Pemeliharaan Darurat Sistem' : 'CRITICAL: Emergency System Maintenance',
                    'message' => $isId
                        ? '<p><strong>PERINGATAN KRITIS:</strong> Pemeliharaan darurat diperlukan.</p><p>Semua layanan akan segera dihentikan. Mohon simpan pekerjaan Anda sekarang.</p>'
                        : '<p><strong>CRITICAL WARNING:</strong> Emergency maintenance is required.</p><p>All services will be stopped immediately. Please save your work now.</p>',
                ],
            ],
            'announcement' => [
                'normal' => [
                    'title' => $isId ? 'Pengumuman Penting' : 'Important Announcement',
                    'message' => $isId
                        ? '<p>Kami ingin menginformasikan kepada Anda tentang pembaruan terbaru dari sistem kami.</p><p>Silakan periksa dashboard untuk informasi lebih lanjut.</p>'
                        : '<p>We would like to inform you about the latest updates from our system.</p><p>Please check the dashboard for more information.</p>',
                ],
                'high' => [
                    'title' => $isId ? 'Pengumuman Mendesak' : 'Urgent Announcement',
                    'message' => $isId
                        ? '<p><strong>Perhatian:</strong> Ada pengumuman penting yang memerlukan tindakan segera dari Anda.</p>'
                        : '<p><strong>Attention:</strong> There is an important announcement that requires your immediate action.</p>',
                ],
                'critical' => [
                    'title' => $isId ? 'PENGUMUMAN KRITIS' : 'CRITICAL ANNOUNCEMENT',
                    'message' => $isId
                        ? '<p><strong>KRITIS:</strong> Diperlukan tindakan segera. Mohon baca dengan seksama.</p>'
                        : '<p><strong>CRITICAL:</strong> Immediate action required. Please read carefully.</p>',
                ],
            ],
            'update' => [
                'normal' => [
                    'title' => $isId ? 'Pembaruan Sistem Tersedia' : 'System Update Available',
                    'message' => $isId
                        ? '<p>Pembaruan sistem baru telah tersedia dengan peningkatan performa dan fitur baru.</p><p>Pembaruan akan diterapkan secara otomatis.</p>'
                        : '<p>A new system update is available with performance improvements and new features.</p><p>The update will be applied automatically.</p>',
                ],
                'high' => [
                    'title' => $isId ? 'Pembaruan Penting Diperlukan' : 'Important Update Required',
                    'message' => $isId
                        ? '<p><strong>Tindakan Diperlukan:</strong> Pembaruan penting harus segera diterapkan untuk keamanan sistem.</p>'
                        : '<p><strong>Action Required:</strong> An important update must be applied immediately for system security.</p>',
                ],
                'critical' => [
                    'title' => $isId ? 'PEMBARUAN KEAMANAN KRITIS' : 'CRITICAL SECURITY UPDATE',
                    'message' => $isId
                        ? '<p><strong>PERINGATAN KEAMANAN:</strong> Pembaruan keamanan kritis harus segera diterapkan.</p>'
                        : '<p><strong>SECURITY WARNING:</strong> A critical security update must be applied immediately.</p>',
                ],
            ],
            'security' => [
                'normal' => [
                    'title' => $isId ? 'Pemberitahuan Keamanan' : 'Security Notice',
                    'message' => $isId
                        ? '<p>Ini adalah pemberitahuan keamanan rutin. Pastikan kredensial Anda tetap aman.</p>'
                        : '<p>This is a routine security notice. Please ensure your credentials remain secure.</p>',
                ],
                'high' => [
                    'title' => $isId ? 'Peringatan Keamanan' : 'Security Alert',
                    'message' => $isId
                        ? '<p><strong>Peringatan:</strong> Aktivitas mencurigakan terdeteksi. Mohon verifikasi aktivitas akun Anda.</p>'
                        : '<p><strong>Warning:</strong> Suspicious activity detected. Please verify your account activity.</p>',
                ],
                'critical' => [
                    'title' => $isId ? 'PERINGATAN KEAMANAN KRITIS' : 'CRITICAL SECURITY ALERT',
                    'message' => $isId
                        ? '<p><strong>KRITIS:</strong> Ancaman keamanan terdeteksi. Tindakan segera diperlukan untuk melindungi akun Anda.</p>'
                        : '<p><strong>CRITICAL:</strong> Security threat detected. Immediate action required to protect your account.</p>',
                ],
            ],
        ];

        $categoryTemplates = $templates[$category] ?? $templates['announcement'];

        return $categoryTemplates[$priority] ?? $categoryTemplates['normal'];
    }
}

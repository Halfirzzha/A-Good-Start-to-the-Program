<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceSettingResource\Pages;
use App\Models\MaintenanceSetting;
use App\Models\MaintenanceToken;
use App\Support\AIService;
use App\Support\AuthHelper;
use App\Support\MaintenanceTokenService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

class MaintenanceSettingResource extends Resource
{
    protected static ?string $model = MaintenanceSetting::class;

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.maintenance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('ui.maintenance.settings.section.title'))
                ->description(__('ui.maintenance.settings.section.description'))
                ->headerActions([
                    Action::make('auto_fill')
                        ->label(__('ui.maintenance.settings.actions.auto_fill'))
                        ->icon('heroicon-o-sparkles')
                        ->iconButton()
                        ->tooltip(__('ui.maintenance.settings.actions.auto_fill_tooltip'))
                        ->action(function (Get $get, Set $set): void {
                            $mode = (string) $get('mode');
                            $currentTitle = (string) $get('title');
                            $language = app()->getLocale();

                            // Try AI first
                            $aiService = new AIService;
                            if ($aiService->isEnabled()) {
                                $context = self::buildAIContext($get);
                                $aiContent = $aiService->generateMaintenanceContent($mode, $context, $language);

                                if ($aiContent) {
                                    $set('title', $aiContent['title']);
                                    $set('summary', $aiContent['summary']);
                                    $set('note_html', $aiContent['note_html']);

                                    // Show usage stats
                                    $stats = $aiService->getUsageStats();
                                    $usageInfo = $stats['percentage'] > 0
                                        ? ' ('.$stats['percentage'].'% '.__('ui.maintenance.settings.notifications.daily_usage').')'
                                        : '';

                                    Notification::make()
                                        ->title(__('ui.maintenance.settings.notifications.ai_generated'))
                                        ->body(__('ui.maintenance.settings.notifications.ai_generated_body').$usageInfo)
                                        ->success()
                                        ->send();

                                    return;
                                }

                                // AI failed, show warning and fallback
                                Notification::make()
                                    ->title(__('ui.maintenance.settings.notifications.ai_fallback'))
                                    ->body(__('ui.maintenance.settings.notifications.ai_fallback_body'))
                                    ->warning()
                                    ->send();
                            }

                            // Fallback to templates
                            $key = self::pickRandomTemplateKey($mode, $currentTitle);
                            $payload = self::maintenanceTemplatePayload($key);
                            $set('title', $payload['title']);
                            $set('summary', $payload['summary']);
                            $set('note_html', $payload['note_html']);

                            // Notify using template
                            Notification::make()
                                ->title(__('ui.maintenance.settings.notifications.template_used'))
                                ->body(__('ui.maintenance.settings.notifications.template_used_body'))
                                ->info()
                                ->send();
                        })
                        ->visible(fn (): bool => self::canManageMessage()),
                ])
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('enabled')
                            ->label(__('ui.maintenance.settings.fields.enabled'))
                            ->onIcon('heroicon-o-check-circle')
                            ->offIcon('heroicon-o-x-circle')
                            ->onColor('success')
                            ->offColor('danger')
                            ->live()
                            ->afterStateUpdated(function (bool $state, ?Model $record): void {
                                if ($record instanceof MaintenanceSetting) {
                                    $record->update(['enabled' => $state]);
                                    Cache::forget('maintenance_settings');

                                    // AI-smart notification based on state
                                    $aiService = new AIService;
                                    $message = $state
                                        ? __('ui.maintenance.settings.notifications.enabled_live')
                                        : __('ui.maintenance.settings.notifications.disabled_live');

                                    Notification::make()
                                        ->title($state ? __('ui.maintenance.settings.notifications.maintenance_on') : __('ui.maintenance.settings.notifications.maintenance_off'))
                                        ->body($message)
                                        ->icon($state ? 'heroicon-o-wrench-screwdriver' : 'heroicon-o-check-circle')
                                        ->iconColor($state ? 'warning' : 'success')
                                        ->success()
                                        ->send();
                                }
                            })
                            ->disabled(fn (): bool => ! self::canManageAccess())
                            ->helperText(__('ui.maintenance.settings.fields.enabled_help')),
                        Select::make('mode')
                            ->label(__('ui.maintenance.settings.fields.mode'))
                            ->prefixIcon('heroicon-o-funnel')
                            ->options([
                                'global' => __('ui.maintenance.settings.mode_options.global'),
                                'allowlist' => __('ui.maintenance.settings.mode_options.allowlist'),
                                'denylist' => __('ui.maintenance.settings.mode_options.denylist'),
                            ])
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, ?Model $record, Set $set): void {
                                if ($record instanceof MaintenanceSetting && $state !== null) {
                                    $record->update(['mode' => $state]);
                                    Cache::forget('maintenance_settings');

                                    // AI-smart auto-fill suggestion
                                    $aiService = new AIService;
                                    $modeLabel = match ($state) {
                                        'global' => __('ui.maintenance.settings.mode_options.global'),
                                        'allowlist' => __('ui.maintenance.settings.mode_options.allowlist'),
                                        'denylist' => __('ui.maintenance.settings.mode_options.denylist'),
                                        default => $state,
                                    };

                                    if ($aiService->isEnabled()) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.settings.notifications.mode_changed'))
                                            ->body(__('ui.maintenance.settings.notifications.mode_changed_body', ['mode' => $modeLabel]).' '.__('ui.maintenance.settings.actions.ai_suggest'))
                                            ->icon('heroicon-o-sparkles')
                                            ->iconColor('info')
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title(__('ui.maintenance.settings.notifications.mode_changed'))
                                            ->body(__('ui.maintenance.settings.notifications.mode_saved').': '.$modeLabel)
                                            ->success()
                                            ->send();
                                    }
                                }
                            })
                            ->disabled(fn (): bool => ! self::canManageAccess())
                            ->required(),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('title')
                            ->label(__('ui.maintenance.settings.fields.title'))
                            ->prefixIcon('heroicon-o-megaphone')
                            ->disabled(fn (): bool => ! self::canManageMessage())
                            ->maxLength(160),
                        Textarea::make('summary')
                            ->label(__('ui.maintenance.settings.fields.summary'))
                            ->hintIcon('heroicon-o-document-text')
                            ->rows(3)
                            ->disabled(fn (): bool => ! self::canManageMessage())
                            ->maxLength(800),
                    ]),
                    RichEditor::make('note_html')
                        ->label(__('ui.maintenance.settings.fields.note_html'))
                        ->hintIcon('heroicon-o-pencil-square')
                        ->toolbarButtons([
                            'bold',
                            'italic',
                            'bulletList',
                            'orderedList',
                            'link',
                            'blockquote',
                            'codeBlock',
                        ])
                        ->disabled(fn (): bool => ! self::canManageMessage())
                        ->columnSpanFull(),
                    Section::make(__('ui.maintenance.settings.sections.schedule'))
                        ->description(__('ui.maintenance.settings.sections.schedule_desc'))
                        ->schema([
                            DateTimePicker::make('start_at')
                                ->label(__('ui.maintenance.settings.fields.start_at'))
                                ->prefixIcon('heroicon-o-calendar-days')
                                ->timezone('UTC')
                                ->seconds(false)
                                ->disabled(fn (): bool => ! self::canManageSchedule()),
                            DateTimePicker::make('end_at')
                                ->label(__('ui.maintenance.settings.fields.end_at'))
                                ->prefixIcon('heroicon-o-calendar-days')
                                ->timezone('UTC')
                                ->seconds(false)
                                ->disabled(fn (): bool => ! self::canManageSchedule()),
                            TextInput::make('retry_after')
                                ->label(__('ui.maintenance.settings.fields.retry_after'))
                                ->prefixIcon('heroicon-o-clock')
                                ->numeric()
                                ->minValue(0)
                                ->disabled(fn (): bool => ! self::canManageSchedule())
                                ->helperText(__('ui.maintenance.settings.fields.retry_after_help')),
                        ])
                        ->columns(3),
                    Section::make(__('ui.maintenance.settings.sections.access'))
                        ->description(__('ui.maintenance.settings.sections.access_desc'))
                        ->schema([
                            Select::make('allow_roles')
                                ->label(__('ui.maintenance.settings.fields.allow_roles'))
                                ->prefixIcon('heroicon-o-user-group')
                                ->multiple()
                                ->searchable()
                                ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                                ->disabled(fn (): bool => ! self::canManageAccess()),
                            TagsInput::make('allow_ips')
                                ->label(__('ui.maintenance.settings.fields.allow_ips'))
                                ->prefixIcon('heroicon-o-globe-alt')
                                ->placeholder(__('ui.maintenance.settings.placeholders.allow_ips'))
                                ->disabled(fn (): bool => ! self::canManageAccess())
                                ->nestedRecursiveRules([
                                    'string',
                                    'max:64',
                                ]),
                            TagsInput::make('allow_paths')
                                ->label(__('ui.maintenance.settings.fields.allow_paths'))
                                ->prefixIcon('heroicon-o-link')
                                ->placeholder(__('ui.maintenance.settings.placeholders.allow_paths'))
                                ->disabled(fn (): bool => ! self::canManageAccess())
                                ->nestedRecursiveRules(['string', 'max:255']),
                            TagsInput::make('deny_paths')
                                ->label(__('ui.maintenance.settings.fields.deny_paths'))
                                ->prefixIcon('heroicon-o-no-symbol')
                                ->placeholder(__('ui.maintenance.settings.placeholders.deny_paths'))
                                ->disabled(fn (): bool => ! self::canManageAccess())
                                ->nestedRecursiveRules(['string', 'max:255']),
                            TagsInput::make('allow_routes')
                                ->label(__('ui.maintenance.settings.fields.allow_routes'))
                                ->prefixIcon('heroicon-o-map')
                                ->placeholder(__('ui.maintenance.settings.placeholders.allow_routes'))
                                ->disabled(fn (): bool => ! self::canManageAccess())
                                ->nestedRecursiveRules(['string', 'max:255']),
                            TagsInput::make('deny_routes')
                                ->label(__('ui.maintenance.settings.fields.deny_routes'))
                                ->prefixIcon('heroicon-o-shield-exclamation')
                                ->placeholder(__('ui.maintenance.settings.placeholders.deny_routes'))
                                ->disabled(fn (): bool => ! self::canManageAccess())
                                ->nestedRecursiveRules(['string', 'max:255']),
                            Toggle::make('allow_api')
                                ->label(__('ui.maintenance.settings.fields.allow_api'))
                                ->onIcon('heroicon-o-check-circle')
                                ->offIcon('heroicon-o-x-circle')
                                ->onColor('success')
                                ->offColor('gray')
                                ->live()
                                ->afterStateUpdated(function (bool $state, ?Model $record): void {
                                    if ($record instanceof MaintenanceSetting) {
                                        $record->update(['allow_api' => $state]);
                                        Cache::forget('maintenance_settings');

                                        Notification::make()
                                            ->title($state ? __('ui.maintenance.settings.notifications.api_allowed') : __('ui.maintenance.settings.notifications.api_blocked'))
                                            ->icon($state ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
                                            ->iconColor($state ? 'success' : 'warning')
                                            ->success()
                                            ->send();
                                    }
                                })
                                ->disabled(fn (): bool => ! self::canManageAccess()),
                            Toggle::make('allow_developer_bypass')
                                ->label(__('ui.maintenance.settings.fields.allow_developer_bypass'))
                                ->onIcon('heroicon-o-check-circle')
                                ->offIcon('heroicon-o-x-circle')
                                ->onColor('success')
                                ->offColor('gray')
                                ->live()
                                ->afterStateUpdated(function (bool $state, ?Model $record): void {
                                    if ($record instanceof MaintenanceSetting) {
                                        $record->update(['allow_developer_bypass' => $state]);
                                        Cache::forget('maintenance_settings');

                                        Notification::make()
                                            ->title($state ? __('ui.maintenance.settings.notifications.dev_bypass_on') : __('ui.maintenance.settings.notifications.dev_bypass_off'))
                                            ->icon('heroicon-o-code-bracket')
                                            ->iconColor($state ? 'success' : 'warning')
                                            ->success()
                                            ->send();
                                    }
                                })
                                ->disabled(fn (): bool => ! self::canManageAccess()),
                        ])
                        ->columns(2),
                    Section::make(__('ui.maintenance.settings.sections.tokens'))
                        ->description(__('ui.maintenance.settings.sections.tokens_desc'))
                        ->headerActions([
                            Action::make('create_token')
                                ->label(__('ui.maintenance.settings.actions.create_token'))
                                ->icon('heroicon-o-plus')
                                ->iconButton()
                                ->tooltip(__('ui.maintenance.settings.actions.create_token'))
                                ->form([
                                    TextInput::make('name')
                                        ->label(__('ui.maintenance.tokens.form.name'))
                                        ->maxLength(100),
                                    DateTimePicker::make('expires_at')
                                        ->label(__('ui.maintenance.tokens.form.expires_at'))
                                        ->timezone('UTC')
                                        ->seconds(false)
                                        ->helperText(__('ui.maintenance.tokens.form.expires_help')),
                                ])
                                ->action(function (array $data): void {
                                    if (! self::canManageTokens()) {
                                        abort(403);
                                    }

                                    if (self::isRateLimited('maintenance_token_create', 6, 60)) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                            ->body(__('ui.maintenance.tokens.notifications.too_many_body_create'))
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    $result = MaintenanceTokenService::create($data, AuthHelper::id());
                                    $plain = $result['token'];

                                    Notification::make()
                                        ->title(__('ui.maintenance.tokens.notifications.created_title'))
                                        ->body(new HtmlString(__('ui.maintenance.tokens.notifications.created_body', ['token' => e($plain)])))
                                        ->success()
                                        ->persistent()
                                        ->send();
                                })
                                ->visible(fn (): bool => self::canManageTokens()),
                            Action::make('revoke_token')
                                ->label(__('ui.maintenance.settings.actions.revoke_token'))
                                ->icon('heroicon-o-lock-closed')
                                ->color('danger')
                                ->iconButton()
                                ->tooltip(__('ui.maintenance.settings.actions.revoke_token'))
                                ->requiresConfirmation()
                                ->form([
                                    Select::make('token_id')
                                        ->label(__('ui.maintenance.settings.fields.token_select'))
                                        ->options(fn (): array => self::tokenOptions())
                                        ->searchable()
                                        ->required(),
                                ])
                                ->action(function (array $data): void {
                                    if (! self::canManageTokens()) {
                                        abort(403);
                                    }

                                    if (self::isRateLimited('maintenance_token_revoke', 8, 60)) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                            ->body(__('ui.maintenance.tokens.notifications.too_many_body_revoke'))
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    $tokenId = (int) ($data['token_id'] ?? 0);
                                    $token = MaintenanceToken::query()->find($tokenId);
                                    if (! $token) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.settings.notifications.token_not_found'))
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    MaintenanceTokenService::revoke($token, AuthHelper::id());
                                    Notification::make()
                                        ->title(__('ui.maintenance.tokens.notifications.revoked_title'))
                                        ->success()
                                        ->send();
                                })
                                ->visible(fn (): bool => self::canManageTokens()),
                            Action::make('delete_token')
                                ->label(__('ui.maintenance.settings.actions.delete_token'))
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->iconButton()
                                ->tooltip(__('ui.maintenance.settings.actions.delete_token'))
                                ->requiresConfirmation()
                                ->form([
                                    Select::make('token_id')
                                        ->label(__('ui.maintenance.settings.fields.token_select'))
                                        ->options(fn (): array => self::tokenOptions())
                                        ->searchable()
                                        ->required(),
                                ])
                                ->action(function (array $data): void {
                                    if (! self::canManageTokens()) {
                                        abort(403);
                                    }

                                    if (self::isRateLimited('maintenance_token_delete', 6, 60)) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.tokens.notifications.too_many_title'))
                                            ->body(__('ui.maintenance.tokens.notifications.too_many_body_revoke'))
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    $tokenId = (int) ($data['token_id'] ?? 0);
                                    $token = MaintenanceToken::query()->find($tokenId);
                                    if (! $token) {
                                        Notification::make()
                                            ->title(__('ui.maintenance.settings.notifications.token_not_found'))
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    $token->delete();
                                    \App\Models\AuditLog::query()
                                        ->where('auditable_type', MaintenanceToken::class)
                                        ->where('auditable_id', $tokenId)
                                        ->delete();
                                    Notification::make()
                                        ->title(__('ui.maintenance.settings.notifications.token_deleted'))
                                        ->success()
                                        ->send();
                                })
                                ->visible(fn (): bool => self::canManageTokens()),
                            Action::make('refresh_tokens')
                                ->icon('heroicon-o-arrow-path')
                                ->color('secondary')
                                ->iconButton()
                                ->tooltip(__('ui.maintenance.settings.actions.refresh'))
                                ->action(fn () => redirect()->to(request()->fullUrl())),
                        ])
                        ->schema([
                            Placeholder::make('tokens_summary')
                                ->label(__('ui.maintenance.settings.fields.tokens_summary'))
                                ->content(fn (): HtmlString => self::renderTokensSummary())
                                ->hintIcon('heroicon-o-key')
                                ->hintIconTooltip(__('ui.maintenance.settings.tokens.summary_hint')),
                        ]),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated(false)
            ->poll('15s')
            ->columns([
                IconColumn::make('enabled')
                    ->label(__('ui.maintenance.settings.table.status'))
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('mode')
                    ->label(__('ui.maintenance.settings.table.mode'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'allowlist' => __('ui.maintenance.settings.mode_labels.allowlist'),
                        'denylist' => __('ui.maintenance.settings.mode_labels.denylist'),
                        default => __('ui.maintenance.settings.mode_labels.global'),
                    }),
                TextColumn::make('window')
                    ->label(__('ui.maintenance.settings.table.schedule'))
                    ->getStateUsing(function (MaintenanceSetting $record): string {
                        $start = $record->start_at?->format('d M Y, H:i') ?? '—';
                        $end = $record->end_at?->format('d M Y, H:i') ?? '—';

                        return $start.' → '.$end;
                    }),
                TextColumn::make('updatedBy.name')
                    ->label(__('ui.maintenance.settings.table.updated_by'))
                    ->placeholder(__('ui.common.system')),
            ])
            ->emptyStateHeading(__('ui.maintenance.settings.empty.heading'))
            ->emptyStateDescription(__('ui.maintenance.settings.empty.description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('ui.maintenance.settings.empty.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
            ]);
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceSettings::route('/'),
            'edit' => Pages\EditMaintenanceSetting::route('/{record}/edit'),
        ];
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

        return $user->can('view_any_maintenance_setting')
            || $user->can('view_maintenance_setting')
            || $user->can('view_any_maintenance_settings')
            || $user->can('view_maintenance_settings');
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

        return $user->can('update_maintenance_setting')
            || $user->can('update_maintenance_settings');
    }

    public static function canManageSchedule(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_maintenance_schedule')
            || self::canUpdateSettings();
    }

    public static function canManageMessage(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_maintenance_message')
            || self::canUpdateSettings();
    }

    public static function canManageAccess(): bool
    {
        $user = AuthHelper::user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasElevatedPrivileges') && $user->hasElevatedPrivileges()) {
            return true;
        }

        return $user->can('manage_maintenance_access')
            || self::canUpdateSettings();
    }

    private static function canManageTokens(): bool
    {
        $user = AuthHelper::user();

        return $user
            && method_exists($user, 'isDeveloper')
            && $user->isDeveloper()
            && $user->can('execute_maintenance_bypass_token');
    }

    private static function renderTokensSummary(): HtmlString
    {
        $tokens = MaintenanceToken::query()
            ->latest('id')
            ->limit(5)
            ->get();

        if ($tokens->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-500">'.__('ui.maintenance.settings.tokens.none').'</span>');
        }

        $rows = $tokens->map(function (MaintenanceToken $token): string {
            $status = $token->isActive() ? 'Active' : 'Revoked/Expired';
            $expires = $token->expires_at ? $token->expires_at->format('d M Y, H:i') : '—';
            $name = e($token->name ?: 'Token');

            return "<li><strong>{$name}</strong> · {$status} · Exp: {$expires}</li>";
        })->implode('');

        return new HtmlString('<ul class="text-sm space-y-1">'.$rows.'</ul>');
    }

    /**
     * @return array<int, string>
     */
    private static function tokenOptions(): array
    {
        return MaintenanceToken::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(function (MaintenanceToken $token): array {
                $status = $token->isActive() ? 'Active' : 'Revoked/Expired';
                $name = $token->name ?: 'Token';
                $label = "{$name} #{$token->getKey()} ({$status})";

                return [$token->getKey() => $label];
            })
            ->all();
    }

    /**
     * @return array{title: string, summary: string, note_html: string, mode: string}
     */
    private static function maintenanceTemplatePayload(string $key): array
    {
        return match ($key) {
            'global_emergency' => [
                'title' => 'Maintenance darurat sedang berlangsung',
                'summary' => 'Tim kami sedang menangani perbaikan kritikal untuk menjaga stabilitas layanan. Akses publik akan kembali setelah selesai.',
                'note_html' => '<p>Perbaikan kritikal sedang dikerjakan. Monitoring aktif dan rollback siap jika dibutuhkan.</p><ul><li>Komponen terpengaruh: inti layanan</li><li>Estimasi: cek pembaruan berikutnya</li></ul>',
                'mode' => 'global',
            ],
            'global_upgrade' => [
                'title' => 'Upgrade infrastruktur sedang berlangsung',
                'summary' => 'Kami sedang melakukan peningkatan skala dan ketahanan layanan. Akses publik kembali setelah proses selesai.',
                'note_html' => '<p>Upgrade infrastruktur sedang berjalan.</p><ul><li>Backup telah diverifikasi</li><li>Deployment bertahap</li></ul>',
                'mode' => 'global',
            ],
            'global_performance' => [
                'title' => 'Optimasi performa sedang berlangsung',
                'summary' => 'Kami sedang melakukan optimasi untuk meningkatkan kecepatan dan stabilitas layanan.',
                'note_html' => '<p>Optimasi performa aktif.</p><ul><li>Indexing database</li><li>Cache refresh terjadwal</li></ul>',
                'mode' => 'global',
            ],
            'allowlist_standard' => [
                'title' => 'Maintenance dengan akses terbatas',
                'summary' => 'Hanya pengguna yang diizinkan dapat mengakses layanan selama maintenance.',
                'note_html' => '<p>Akses dibatasi berdasarkan allowlist. Pastikan role, IP, atau route sudah diset.</p><ul><li>Cek allow roles</li><li>Cek allow paths/routes</li></ul>',
                'mode' => 'allowlist',
            ],
            'allowlist_partner' => [
                'title' => 'Maintenance untuk akses mitra',
                'summary' => 'Akses hanya tersedia untuk mitra/pelanggan prioritas selama maintenance berlangsung.',
                'note_html' => '<p>Allowlist aktif untuk mitra.</p><ul><li>Pastikan role mitra aktif</li><li>Validasi allow routes penting</li></ul>',
                'mode' => 'allowlist',
            ],
            'denylist_security' => [
                'title' => 'Maintenance keamanan (blokir selektif)',
                'summary' => 'Sebagian endpoint diblokir sementara untuk patch keamanan.',
                'note_html' => '<p>Denylist aktif untuk endpoint tertentu. Pantau log akses untuk memastikan jalur penting tetap berjalan.</p><ul><li>Cek deny paths/routes</li><li>Pastikan API kritikal tetap tersedia</li></ul>',
                'mode' => 'denylist',
            ],
            'denylist_hotfix' => [
                'title' => 'Hotfix endpoint tertentu',
                'summary' => 'Perbaikan cepat untuk endpoint tertentu. Layanan lain tetap berjalan normal.',
                'note_html' => '<p>Denylist diterapkan untuk endpoint terdampak.</p><ul><li>Cek deny routes</li><li>Komunikasikan ke tim terkait</li></ul>',
                'mode' => 'denylist',
            ],
            default => [
                'title' => 'Maintenance terjadwal sedang berlangsung',
                'summary' => 'Tim kami sedang melakukan peningkatan stabilitas, keamanan, dan performa. Akses publik akan kembali setelah selesai.',
                'note_html' => '<p>Maintenance terjadwal aktif. Semua layanan akan kembali normal setelah window selesai.</p><ul><li>Cek jadwal start/end</li><li>Update status jika ada perubahan</li></ul>',
                'mode' => 'global',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function templateKeysForMode(string $mode): array
    {
        return match ($mode) {
            'allowlist' => [
                'allowlist_standard',
                'allowlist_partner',
            ],
            'denylist' => [
                'denylist_security',
                'denylist_hotfix',
            ],
            default => [
                'global_planned',
                'global_emergency',
                'global_upgrade',
                'global_performance',
            ],
        };
    }

    private static function pickRandomTemplateKey(string $mode, string $currentTitle): string
    {
        $templates = self::templateKeysForMode($mode);
        $filtered = [];

        foreach ($templates as $key) {
            $payload = self::maintenanceTemplatePayload($key);
            if ($payload['title'] !== $currentTitle) {
                $filtered[] = $key;
            }
        }

        if ($filtered === []) {
            return $templates[0] ?? 'global_planned';
        }

        return $filtered[array_rand($filtered)];
    }

    private static function isRateLimited(string $key, int $maxAttempts, int $seconds): bool
    {
        $userId = AuthHelper::id() ?: 'guest';
        $cacheKey = "rate:maintenance_settings:{$key}:{$userId}";

        $attempts = Cache::increment($cacheKey);
        if ($attempts === 1) {
            Cache::put($cacheKey, 1, now()->addSeconds($seconds));
        }

        return $attempts > $maxAttempts;
    }

    /**
     * Build context for AI content generation based on current form state.
     */
    private static function buildAIContext(Get $get): string
    {
        $parts = [];

        // Include schedule info if available
        $startAt = $get('start_at');
        $endAt = $get('end_at');
        if ($startAt || $endAt) {
            $parts[] = 'Scheduled maintenance window: '.($startAt ?? 'now').' to '.($endAt ?? 'TBD');
        }

        // Include access control info
        $allowRoles = $get('allow_roles');
        if (! empty($allowRoles) && is_array($allowRoles)) {
            $parts[] = 'Allowed roles: '.implode(', ', $allowRoles);
        }

        $allowIps = $get('allow_ips');
        if (! empty($allowIps) && is_array($allowIps)) {
            $parts[] = 'IP whitelist configured';
        }

        $denyPaths = $get('deny_paths');
        if (! empty($denyPaths) && is_array($denyPaths)) {
            $parts[] = 'Blocked paths: '.implode(', ', $denyPaths);
        }

        $denyRoutes = $get('deny_routes');
        if (! empty($denyRoutes) && is_array($denyRoutes)) {
            $parts[] = 'Blocked routes: '.implode(', ', $denyRoutes);
        }

        $allowApi = $get('allow_api');
        if ($allowApi) {
            $parts[] = 'API access remains enabled';
        }

        $allowDeveloperBypass = $get('allow_developer_bypass');
        if ($allowDeveloperBypass) {
            $parts[] = 'Developer bypass enabled';
        }

        if (empty($parts)) {
            return 'Standard maintenance procedure';
        }

        return implode('. ', $parts);
    }
}

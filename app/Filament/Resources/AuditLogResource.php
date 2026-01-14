<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\AuthHelper;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('ui.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.audit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.security');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('ui.audit.sections.summary'))
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('ui.audit.columns.time'))
                        ->formatStateUsing(fn (AuditLog $record): string => self::formatTimestamp($record->created_at))
                        ->helperText(fn (AuditLog $record): string => self::formatRelative($record->created_at)),
                    TextEntry::make('action')
                        ->label(__('ui.audit.columns.action'))
                        ->badge(),
                    TextEntry::make('status_code')
                        ->label(__('ui.audit.columns.status'))
                        ->badge(),
                    TextEntry::make('auditable_type')->label(__('ui.audit.columns.entity_type')),
                    TextEntry::make('auditable_id')->label(__('ui.audit.columns.entity_id')),
                    TextEntry::make('auditable_label')->label(__('ui.audit.columns.entity_label'))->visible(fn (AuditLog $record): bool => filled($record->auditable_label)),
                ])
                ->columns(2),
            Section::make(__('ui.audit.sections.actor'))
                ->schema([
                    TextEntry::make('user.email')->label(__('ui.audit.columns.user')),
                    TextEntry::make('user_name')->label(__('ui.audit.columns.user_name'))->visible(fn (AuditLog $record): bool => filled($record->user_name)),
                    TextEntry::make('user_email')->label(__('ui.audit.columns.user_email'))->visible(fn (AuditLog $record): bool => filled($record->user_email)),
                    TextEntry::make('user_username')->label(__('ui.audit.columns.username'))->visible(fn (AuditLog $record): bool => filled($record->user_username)),
                    TextEntry::make('role_name')->label(__('ui.audit.columns.role')),
                    TextEntry::make('hash')->label(__('ui.audit.columns.hash')),
                    TextEntry::make('previous_hash')->label(__('ui.audit.columns.previous_hash')),
                ])
                ->columns(2),
            Section::make(__('ui.audit.sections.request'))
                ->schema([
                    TextEntry::make('method')->label(__('ui.audit.columns.method')),
                    TextEntry::make('route')->label(__('ui.audit.columns.route')),
                    TextEntry::make('url')->label(__('ui.audit.columns.url'))->wrap(),
                    TextEntry::make('request_referer')->label(__('ui.audit.columns.referer'))->visible(fn (AuditLog $record): bool => filled($record->request_referer)),
                    TextEntry::make('ip_address')->label(__('ui.audit.columns.ip')),
                    TextEntry::make('user_agent')->label(__('ui.audit.columns.user_agent'))->wrap(),
                    TextEntry::make('user_agent_hash')->label(__('ui.audit.columns.user_agent_hash'))->visible(fn (AuditLog $record): bool => filled($record->user_agent_hash)),
                    TextEntry::make('request_payload_hash')->label(__('ui.audit.columns.request_payload_hash'))->visible(fn (AuditLog $record): bool => filled($record->request_payload_hash)),
                    TextEntry::make('request_id')->label(__('ui.audit.columns.request_id'))->copyable(),
                    TextEntry::make('session_id')->label(__('ui.audit.columns.session_id'))->copyable(),
                    TextEntry::make('duration_ms')->label(__('ui.audit.columns.duration_ms')),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make(__('ui.audit.sections.changes'))
                ->schema([
                    KeyValueEntry::make('old_values')
                        ->label(__('ui.audit.changes.old'))
                        ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->old_values)),
                    KeyValueEntry::make('new_values')
                        ->label(__('ui.audit.changes.new'))
                        ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->new_values)),
                    KeyValueEntry::make('context')
                        ->label(__('ui.audit.changes.context'))
                        ->getStateUsing(fn (AuditLog $record): array => self::normalizeKeyValue($record->context)),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('ui.audit.columns.time'))
                    ->sortable()
                    ->formatStateUsing(fn (AuditLog $record): string => self::formatRelative($record->created_at))
                    ->description(fn (AuditLog $record): string => self::formatTimestamp($record->created_at)),
                TextColumn::make('action')
                    ->label(__('ui.audit.columns.action'))
                    ->badge()
                    ->searchable()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'created') => 'success',
                        str_contains($state, 'updated') => 'warning',
                        str_contains($state, 'deleted') => 'danger',
                        str_contains($state, 'login') => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status_code')
                    ->label(__('ui.audit.columns.status'))
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 200 => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('method')
                    ->label(__('ui.audit.columns.method'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user')
                    ->label(__('ui.audit.columns.user'))
                    ->getStateUsing(function (AuditLog $record): string {
                        $user = $record->user;
                        if (! $user) {
                            return __('ui.common.system');
                        }
                        return $user->name ?: $user->email ?: $user->username ?: __('ui.common.user');
                    })
                    ->description(function (AuditLog $record): ?string {
                        $user = $record->user;
                        if (! $user) {
                            return null;
                        }
                        $role = $user->role ?: $user->getRoleNames()->first();
                        return $role ? __('ui.common.role_prefix', ['role' => $role]) : null;
                    })
                    ->searchable(),
                TextColumn::make('role_name')
                    ->label(__('ui.audit.columns.role'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('target')
                    ->label(__('ui.audit.columns.target'))
                    ->badge()
                    ->getStateUsing(fn (AuditLog $record): string => $record->auditable_type ? class_basename($record->auditable_type) : '—')
                    ->description(function (AuditLog $record): ?string {
                        if (! $record->auditable_id && ! $record->auditable_label) {
                            return null;
                        }
                        $parts = [];
                        if ($record->auditable_id) {
                            $parts[] = __('ui.audit.target_meta.id', ['value' => $record->auditable_id]);
                        }
                        if ($record->auditable_label) {
                            $parts[] = __('ui.audit.target_meta.label', ['value' => $record->auditable_label]);
                        }

                        return implode(' · ', $parts);
                    }),
                TextColumn::make('ip_address')
                    ->label(__('ui.audit.columns.ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label(__('ui.audit.columns.request_id'))
                    ->copyable()
                    ->copyMessage(__('ui.audit.copy.request_id'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size(TextSize::ExtraSmall),
                TextColumn::make('session_id')
                    ->label(__('ui.audit.columns.session_id'))
                    ->copyable()
                    ->copyMessage(__('ui.audit.copy.session_id'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size(TextSize::ExtraSmall),
                TextColumn::make('route')
                    ->label(__('ui.audit.columns.route'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('url')
                    ->label(__('ui.audit.columns.url'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('ui.audit.filters.action'))
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->pluck('action', 'action')
                        ->sort()
                        ->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('auditable_type')
                    ->label(__('ui.audit.filters.entity_type'))
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->pluck('auditable_type')
                        ->filter()
                    ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                    ->sort()
                    ->all())
                    ->searchable(),
                SelectFilter::make('method')
                    ->label(__('ui.audit.filters.method'))
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->whereNotNull('method')
                        ->pluck('method', 'method')
                        ->sort()
                        ->all()),
                SelectFilter::make('status_code')
                    ->label(__('ui.audit.filters.status'))
                    ->options([
                        '200' => __('ui.audit.status_options.200'),
                        '403' => __('ui.audit.status_options.403'),
                        '404' => __('ui.audit.status_options.404'),
                        '422' => __('ui.audit.status_options.422'),
                        '500' => __('ui.audit.status_options.500'),
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->where('status_code', (int) $value);
                    }),
                SelectFilter::make('role_name')
                    ->label(__('ui.audit.filters.role'))
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->whereNotNull('role_name')
                        ->pluck('role_name', 'role_name')
                        ->sort()
                        ->all())
                    ->searchable(),
            ])
            ->searchable()
            ->filtersLayout(FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->recordActions([
                ViewAction::make()
                    ->authorize('view')
                    ->visible(fn (AuditLog $record): bool => AuthHelper::user()?->can('view', $record) ?? false),
            ])
            ->emptyStateHeading(__('ui.audit.empty.heading'))
            ->emptyStateDescription(__('ui.audit.empty.description'))
            ->emptyStateActions([
                Action::make('refresh')
                    ->label(__('ui.audit.actions.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('secondary')
                    ->url(fn (): string => request()->fullUrl()),
            ])
            ->toolbarActions([]);
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

        return $user->can('view_any_audit_log')
            || $user->can('view_audit_log')
            || $user->can('view_any_audit_logs')
            || $user->can('view_audit_logs');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed> | null  $values
     * @return array<string, string>
     */
    private static function normalizeKeyValue(?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[$key] = self::stringifyValue($value);
        }

        return $normalized;
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '[' . get_debug_type($value) . ']' : $json;
    }

    private static function resolveTimezone(): string
    {
        $user = AuthHelper::user();
        if ($user && property_exists($user, 'timezone') && $user->timezone) {
            return (string) $user->timezone;
        }

        return config('app.timezone', 'UTC');
    }

    private static function formatTimestamp(?Carbon $timestamp): string
    {
        if (! $timestamp) {
            return '—';
        }

        return $timestamp->copy()->setTimezone(self::resolveTimezone())->format('d M Y, H:i:s T');
    }

    private static function formatRelative(?Carbon $timestamp): string
    {
        if (! $timestamp) {
            return '—';
        }

        return $timestamp->copy()->setTimezone(self::resolveTimezone())->diffForHumans();
    }
}

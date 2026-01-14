<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceHistoryResource\Pages;
use App\Models\AuditLog;
use App\Support\AuthHelper;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Support\Enums\TextSize;

class MaintenanceHistoryResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $label = null;

    protected static ?string $pluralLabel = null;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('ui.maintenance.history.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.maintenance.history.model_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.maintenance');
    }

    public static function getLabel(): ?string
    {
        return __('ui.maintenance.history.label');
    }

    public static function getPluralLabel(): ?string
    {
        return __('ui.maintenance.history.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('ui.maintenance.history.columns.time'))
                    ->sortable()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->created_at?->diffForHumans() ?? '—')
                    ->description(fn (AuditLog $record): ?string => $record->created_at?->format('d M Y, H:i:s T')),
                TextColumn::make('action')
                    ->label(__('ui.maintenance.history.columns.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::actionLabel($state)),
                TextColumn::make('token_code')
                    ->label(__('ui.maintenance.history.columns.token_code'))
                    ->getStateUsing(fn (AuditLog $record): ?string => self::tokenCode($record))
                    ->copyable()
                    ->copyMessage(__('ui.maintenance.history.copy.token'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->placeholder('—'),
                TextColumn::make('request_id')
                    ->label(__('ui.maintenance.history.columns.request_id'))
                    ->copyable()
                    ->copyMessage(__('ui.maintenance.history.copy.request_id'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size(TextSize::ExtraSmall),
                TextColumn::make('session_id')
                    ->label(__('ui.maintenance.history.columns.session_id'))
                    ->copyable()
                    ->copyMessage(__('ui.maintenance.history.copy.session_id'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size(TextSize::ExtraSmall),
                TextColumn::make('actor')
                    ->label(__('ui.maintenance.history.columns.user'))
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
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $sub) use ($search): void {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('target')
                    ->label(__('ui.maintenance.history.columns.target'))
                    ->badge()
                    ->getStateUsing(function (AuditLog $record): string {
                        return str_contains($record->action, 'token')
                            ? __('ui.maintenance.history.target.token')
                            : __('ui.maintenance.history.target.maintenance');
                    }),
                TextColumn::make('changes')
                    ->label(__('ui.maintenance.history.columns.changes'))
                    ->getStateUsing(fn (AuditLog $record): string => self::formatChanges($record))
                    ->html()
                    ->wrap(),
                TextColumn::make('context_code')
                    ->label(__('ui.maintenance.history.columns.context_code'))
                    ->getStateUsing(fn (AuditLog $record): ?string => self::formatContext($record))
                    ->copyable()
                    ->copyMessage(__('ui.maintenance.history.copy.context'))
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->size(TextSize::ExtraSmall),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('ui.maintenance.history.columns.action'))
                    ->options(collect(self::actionsFilter())
                        ->mapWithKeys(fn (string $action): array => [$action => self::actionLabel($action)])
                        ->all()),
            ])
            ->modifyQueryUsing(function (Builder $query): Builder {
                return self::maintenanceAuditBaseQuery()
                    ->with('user');
            })
            ->searchable()
            ->filtersLayout(FiltersLayout::Dropdown)
            ->persistFiltersInSession()
            ->toolbarActions([
            ])
            ->emptyStateHeading(__('ui.maintenance.history.empty.heading'))
            ->emptyStateDescription(__('ui.maintenance.history.empty.description'))
            ;
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

        return $user->can('view_any_audit_log') || $user->can('view_audit_log');
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
            'index' => Pages\ListMaintenanceHistories::route('/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function actionsFilter(): array
    {
        return [
            'maintenance_settings_updated',
            'maintenance_schedule_updated',
            'maintenance_enabled',
            'maintenance_disabled',
            'maintenance_auto_enabled',
            'maintenance_auto_disabled',
            'maintenance_note_updated',
            'maintenance_token_created',
            'maintenance_token_rotated',
            'maintenance_token_revoked',
        ];
    }

    private static function actionLabel(string $action): string
    {
        $label = __('ui.maintenance.actions.'.$action);

        return $label === 'ui.maintenance.actions.'.$action ? $action : $label;
    }

    public static function maintenanceAuditBaseQuery(): Builder
    {
        return AuditLog::query()
            ->whereIn('action', self::actionsFilter())
            ->where(function (Builder $inner): void {
                $inner->whereNotIn('action', ['maintenance_token_created', 'maintenance_token_rotated'])
                    ->orWhereNotNull('context->token_plain');
            });
    }

    private static function formatChanges(AuditLog $record): string
    {
        $changes = $record->context['changes'] ?? null;
        if (! is_array($changes) || $changes === []) {
            return __('ui.maintenance.history.changes.none');
        }

        $lines = [];
        foreach ($changes as $change) {
            $field = $change['field'] ?? __('ui.maintenance.history.changes.field_fallback');
            $from = $change['from'] ?? __('ui.maintenance.history.changes.null');
            $to = $change['to'] ?? __('ui.maintenance.history.changes.null');
            $lines[] = e($field) . ': ' . e($from) . ' → ' . e($to);
        }

        return implode('<br>', $lines);
    }

    private static function formatContext(AuditLog $record): ?string
    {
        $context = $record->context;
        if (! is_array($context) || $context === []) {
            return null;
        }

        $payload = [
            'action' => $record->action,
            'request_id' => $record->request_id,
            'session_id' => $record->session_id,
            'ip_address' => $record->ip_address,
            'user_agent' => $record->user_agent,
            'route' => $record->route,
            'method' => $record->method,
            'status_code' => $record->status_code,
            'changes' => $context['changes'] ?? null,
            'meta' => array_diff_key($context, array_flip(['changes'])),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function tokenCode(AuditLog $record): ?string
    {
        $context = $record->context;
        if (! is_array($context)) {
            return null;
        }

        $token = $context['token_plain'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }
}

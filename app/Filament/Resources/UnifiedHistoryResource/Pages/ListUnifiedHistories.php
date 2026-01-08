<?php

namespace App\Filament\Resources\UnifiedHistoryResource\Pages;

use App\Filament\Resources\UnifiedHistoryResource;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class ListUnifiedHistories extends ListRecords
{
    protected static string $resource = UnifiedHistoryResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_history_entry')
                ->label('Add Unified History')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => AuthHelper::user()?->can('execute_unified_history_create') ?? false)
                ->modalHeading('Record Unified History')
                ->modalSubmitActionLabel('Record')
                ->modalWidth('4xl')
                ->form([
                    Section::make('Core')
                        ->schema([
                            Select::make('category')
                                ->label('Category')
                                ->options(UnifiedHistoryResource::historyCategories())
                                ->native(false)
                                ->required(),
                            Select::make('scope')
                                ->label('Scope')
                                ->options(UnifiedHistoryResource::historyScopes())
                                ->native(false)
                                ->multiple(),
                            TextInput::make('title')
                                ->label('Title')
                                ->required()
                                ->maxLength(160),
                            Textarea::make('summary')
                                ->label('Summary')
                                ->required()
                                ->rows(3)
                                ->maxLength(800)
                                ->columnSpanFull(),
                            Textarea::make('details')
                                ->label('Details')
                                ->rows(5)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                    Section::make('Findings & Actions')
                        ->schema([
                            Textarea::make('findings')
                                ->label('Findings')
                                ->rows(4)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                            Textarea::make('recommendations')
                                ->label('Recommendations')
                                ->rows(4)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                            Textarea::make('mitigations')
                                ->label('Mitigations')
                                ->rows(4)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                            Textarea::make('decisions')
                                ->label('Decisions')
                                ->rows(4)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                            Textarea::make('configuration_changes')
                                ->label('Configuration Changes')
                                ->rows(4)
                                ->maxLength(3000)
                                ->columnSpanFull(),
                        ])
                        ->collapsed(),
                    Section::make('Traceability')
                        ->schema([
                            TagsInput::make('tags')
                                ->label('Tags')
                                ->placeholder('hardening, audit, release'),
                            TagsInput::make('references')
                                ->label('References')
                                ->placeholder('ADR-012, ticket-456, docs/link'),
                            TextInput::make('related_request_id')
                                ->label('Related Request ID')
                                ->maxLength(64),
                            TextInput::make('related_audit_id')
                                ->label('Related Audit Log ID')
                                ->numeric(),
                        ])
                        ->columns(2)
                        ->collapsed(),
                ])
                ->action(function (array $data): void {
                    $this->recordUnifiedHistory($data);

                    Notification::make()
                        ->title('Unified history entry recorded.')
                        ->success()
                        ->send();

                    $this->dispatch('refresh-page');
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordUnifiedHistory(array $data): void
    {
        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        $context = [
            'category' => $this->normalizeString($data['category'] ?? null),
            'scope' => $this->normalizeList($data['scope'] ?? []),
            'title' => $this->normalizeString($data['title'] ?? null),
            'summary' => $this->normalizeString($data['summary'] ?? null),
            'details' => $this->normalizeString($data['details'] ?? null),
            'findings' => $this->normalizeString($data['findings'] ?? null),
            'recommendations' => $this->normalizeString($data['recommendations'] ?? null),
            'mitigations' => $this->normalizeString($data['mitigations'] ?? null),
            'decisions' => $this->normalizeString($data['decisions'] ?? null),
            'configuration_changes' => $this->normalizeString($data['configuration_changes'] ?? null),
            'tags' => $this->normalizeList($data['tags'] ?? []),
            'references' => $this->normalizeList($data['references'] ?? []),
            'related_request_id' => $this->normalizeString($data['related_request_id'] ?? null),
            'related_audit_id' => $this->normalizeAuditId($data['related_audit_id'] ?? null),
            'entry_type' => 'manual',
        ];

        $context = array_filter($context, function ($value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null && $value !== '';
        });

        AuditLogWriter::writeAudit([
            'user_id' => AuthHelper::id(),
            'action' => 'unified_history_entry',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->method(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_values(array_filter(array_map(function ($item): string {
            return trim((string) $item);
        }, $value), fn (string $item): bool => $item !== ''));

        return $items;
    }

    private function normalizeAuditId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}

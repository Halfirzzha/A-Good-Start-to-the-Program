<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditExportCommand extends Command
{
    protected $signature = 'audit:export
        {--from-id= : Start export from an audit log ID}
        {--to-id= : End export at an audit log ID}
        {--chunk= : Chunk size for export}
        {--path= : Output path for JSONL export}
        {--format=default : Output format: default|ecs}
        {--include-context : Include context payloads}
        {--include-changes : Include old/new values}';

    protected $description = 'Export audit logs as JSON Lines (SIEM-friendly).';

    public function handle(): int
    {
        if (! Schema::hasTable('audit_logs')) {
            $this->error('audit_logs table is missing.');

            return self::FAILURE;
        }

        $fromId = $this->option('from-id');
        $fromId = is_numeric($fromId) ? (int) $fromId : null;
        $toId = $this->option('to-id');
        $toId = is_numeric($toId) ? (int) $toId : null;
        $chunk = (int) ($this->option('chunk') ?: config('audit.verify_chunk', 500));
        if ($chunk <= 0) {
            $chunk = 500;
        }

        $path = (string) ($this->option('path') ?: storage_path('logs/audit-export-'.now()->format('Ymd-His').'.jsonl'));
        $format = (string) ($this->option('format') ?: 'default');
        if (! in_array($format, ['default', 'ecs'], true)) {
            $this->error('Invalid format. Use default or ecs.');

            return self::FAILURE;
        }
        $handle = @fopen($path, 'wb');
        if (! $handle) {
            $this->error('Failed to open export path: '.$path);

            return self::FAILURE;
        }

        $includeContext = (bool) $this->option('include-context');
        $includeChanges = (bool) $this->option('include-changes');

        $query = DB::table('audit_logs')->orderBy('id');
        if ($fromId) {
            $query->where('id', '>=', $fromId);
        }
        if ($toId) {
            $query->where('id', '<=', $toId);
        }

        $exported = 0;

        $query->chunkById($chunk, function ($rows) use (&$exported, $handle, $includeContext, $includeChanges, $format): void {
            foreach ($rows as $row) {
                $payload = $format === 'ecs'
                    ? $this->formatEcsPayload($row)
                    : $this->formatDefaultPayload($row);

                if ($includeContext) {
                    $context = $this->decodeJsonColumn($row->context);
                    $payload['context'] = $this->redactSensitive($context);
                }

                if ($includeChanges) {
                    $payload['old_values'] = $this->redactSensitive($this->decodeJsonColumn($row->old_values));
                    $payload['new_values'] = $this->redactSensitive($this->decodeJsonColumn($row->new_values));
                }

                fwrite($handle, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
                $exported++;
            }
        });

        fclose($handle);

        $this->info("Exported {$exported} audit logs to {$path}.");

        return self::SUCCESS;
    }

    private function formatDefaultPayload(object $row): array
    {
        return [
            'id' => $row->id,
            'created_at' => $row->created_at,
            'user_id' => $row->user_id,
            'user_name' => $row->user_name,
            'user_email' => $row->user_email,
            'user_username' => $row->user_username,
            'role_name' => $row->role_name,
            'action' => $row->action,
            'auditable_type' => $row->auditable_type,
            'auditable_id' => $row->auditable_id,
            'ip_address' => $row->ip_address,
            'user_agent_hash' => $row->user_agent_hash,
            'url' => $row->url,
            'route' => $row->route,
            'method' => $row->method,
            'status_code' => $row->status_code,
            'request_id' => $row->request_id,
            'session_id' => $row->session_id,
            'duration_ms' => $row->duration_ms,
            'request_payload_hash' => $row->request_payload_hash,
            'previous_hash' => $row->previous_hash,
            'hash' => $row->hash,
            'signature' => $row->signature,
        ];
    }

    private function formatEcsPayload(object $row): array
    {
        $action = (string) ($row->action ?? '');
        $category = $this->mapEventCategory($action);
        $outcome = $row->status_code && (int) $row->status_code >= 400 ? 'failure' : 'success';
        $type = $outcome === 'failure' ? ['error'] : ['info'];

        return [
            '@timestamp' => $row->created_at,
            'event' => [
                'kind' => 'event',
                'category' => [$category],
                'type' => $type,
                'action' => $action,
                'id' => $row->request_id,
                'duration' => $row->duration_ms ? (int) $row->duration_ms * 1000000 : null,
                'outcome' => $outcome,
            ],
            'user' => [
                'id' => $row->user_id,
                'name' => $row->user_username ?: $row->user_name,
                'email' => $row->user_email,
                'roles' => $row->role_name ? [$row->role_name] : [],
            ],
            'source' => [
                'ip' => $row->ip_address,
            ],
            'http' => [
                'request' => [
                    'method' => $row->method,
                ],
                'response' => [
                    'status_code' => $row->status_code,
                ],
            ],
            'url' => [
                'original' => $row->url,
            ],
            'labels' => [
                'route' => $row->route,
                'auditable_type' => $row->auditable_type,
                'auditable_id' => $row->auditable_id,
                'request_payload_hash' => $row->request_payload_hash,
                'previous_hash' => $row->previous_hash,
                'hash' => $row->hash,
                'signature' => $row->signature,
                'session_id' => $row->session_id,
                'user_agent_hash' => $row->user_agent_hash,
            ],
        ];
    }

    private function mapEventCategory(string $action): string
    {
        $action = strtolower($action);

        if ($action === '') {
            return 'configuration';
        }

        if (str_contains($action, 'auth') || str_contains($action, 'login') || str_contains($action, 'otp')) {
            return 'authentication';
        }

        if (str_contains($action, 'role') || str_contains($action, 'permission') || str_contains($action, 'user')) {
            return 'iam';
        }

        if (str_contains($action, 'maintenance') || str_contains($action, 'setting')) {
            return 'configuration';
        }

        if (str_contains($action, 'security') || str_contains($action, 'threat')) {
            return 'security';
        }

        return 'configuration';
    }

    private function decodeJsonColumn(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function redactSensitive(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sensitive = array_map('strtolower', config('audit.sensitive_keys', []));
        $redacted = [];

        foreach ($payload as $key => $value) {
            $keyString = strtolower((string) $key);
            $shouldRedact = false;

            foreach ($sensitive as $needle) {
                if ($needle !== '' && str_contains($keyString, $needle)) {
                    $shouldRedact = true;
                    break;
                }
            }

            if ($shouldRedact) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactSensitive($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}

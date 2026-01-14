<?php

namespace App\Models\Concerns;

use App\Support\AuditLogWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAudit('created', null, $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            if (empty($changes)) {
                return;
            }

            $original = Arr::only($model->getOriginal(), array_keys($changes));
            $model->writeAudit('updated', $original, $changes);
        });

        static::deleted(function (Model $model): void {
            $model->writeAudit('deleted', $model->getOriginal(), null);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model): void {
                $model->writeAudit('restored', null, $model->getAttributes());
            });

            static::forceDeleted(function (Model $model): void {
                $model->writeAudit('force_deleted', $model->getOriginal(), null);
            });
        }
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function writeAudit(string $action, ?array $oldValues, ?array $newValues): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => Auth::id(),
            'action' => $this->normalizeAction($action),
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'auditable_label' => $this->resolveAuditLabel(),
            'old_values' => $this->filterAuditableValues($oldValues),
            'new_values' => $this->filterAuditableValues($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $this->truncate((string) ($request?->userAgent() ?? ''), 255),
            'url' => $this->truncate((string) ($request?->fullUrl() ?? ''), 2000),
            'route' => $this->truncate((string) (optional($request?->route())->getName()), 255),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $this->truncate((string) $sessionId, 100),
            'duration_ms' => null,
            'context' => [
                'source' => app()->runningInConsole() ? 'console' : 'http',
            ],
            'created_at' => now(),
        ]);
    }

    protected function resolveAuditLabel(): ?string
    {
        $candidates = ['name', 'title', 'code', 'number', 'email', 'username'];

        foreach ($candidates as $field) {
            if (! array_key_exists($field, $this->getAttributes())) {
                continue;
            }

            $value = $this->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return Str::limit($value, 190, '');
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    protected function filterAuditableValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $exclude = $this->auditExclude();
        $filtered = [];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $exclude, true)) {
                $filtered[$key] = '[redacted]';
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    protected function auditExclude(): array
    {
        $default = [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'security_stamp',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        $custom = property_exists($this, 'auditExclude') && is_array($this->auditExclude)
            ? $this->auditExclude
            : [];

        return array_values(array_unique(array_map('strtolower', array_merge($default, $custom))));
    }

    protected function normalizeAction(string $action): string
    {
        return substr($action, 0, 100);
    }

    protected function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}

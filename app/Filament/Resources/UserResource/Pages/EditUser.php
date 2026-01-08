<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\AccountStatus;
use App\Filament\Resources\UserResource;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\SecurityAlert;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private ?string $selectedRole = null;
    private ?string $previousRole = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('avatar', $data) && $this->record?->avatar && $data['avatar'] !== $this->record->avatar) {
            UserResource::queueAvatarDeletion($this->record->avatar);
        }

        if (array_key_exists('role', $data)) {
            $this->previousRole = $this->record?->role;
            $role = $data['role'];
            if (! is_string($role)) {
                abort(403, 'Role assignment denied.');
            }

            if ($this->record?->isDeveloper()) {
                $developerRole = (string) config('security.developer_role', 'developer');
                if ($role !== $developerRole) {
                    abort(403, 'Developer role is immutable.');
                }
                $this->selectedRole = $developerRole;
            } elseif (! UserResource::canAssignRoleName($role, AuthHelper::user(), $this->record)) {
                if ($role !== $this->record->role) {
                    abort(403, 'Role assignment denied.');
                }
                unset($data['role']);
            } else {
                $this->selectedRole = $role;
            }
        }

        $status = $data['account_status'] ?? null;

        if (in_array($status, [
            AccountStatus::Blocked->value,
            AccountStatus::Suspended->value,
            AccountStatus::Terminated->value,
        ], true)) {
            $data['blocked_by'] ??= $this->record->blocked_by ?? AuthHelper::id();
            $data['blocked_reason'] ??= $this->record->blocked_reason ?? 'Status change';
        }

        if ($status === AccountStatus::Active->value) {
            $data['blocked_by'] = null;
            $data['blocked_reason'] = null;
            $data['blocked_until'] = null;
        }

        if (empty($data['two_factor_enabled'])) {
            $data['two_factor_method'] = null;
        }

        if (empty($data['locale'])) {
            $data['locale'] = $this->record?->locale
                ?: (string) (UserResource::detectLocale() ?: config('app.locale', 'en'));
        }

        if (empty($data['timezone'])) {
            $data['timezone'] = $this->record?->timezone
                ?: (string) (UserResource::detectTimezone() ?: config('app.timezone', 'UTC'));
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->record || ! $this->selectedRole) {
            return;
        }

        $this->record->syncRoles([$this->selectedRole]);
        $this->record->forceFill([
            'role' => $this->selectedRole,
        ])->save();

        $this->recordRoleChange($this->previousRole, $this->selectedRole);
    }

    private function recordRoleChange(?string $previousRole, string $newRole): void
    {
        if (! $this->record || $previousRole === $newRole) {
            return;
        }

        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => AuthHelper::id(),
            'action' => 'user_role_changed',
            'auditable_type' => $this->record->getMorphClass(),
            'auditable_id' => $this->record->getKey(),
            'old_values' => $previousRole ? ['role' => $previousRole] : null,
            'new_values' => ['role' => $newRole],
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255) : null,
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->getMethod(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'actor_id' => AuthHelper::id(),
                'target_user_id' => $this->record->getKey(),
                'target_email' => $this->record->email,
                'target_username' => $this->record->username,
                'from_role' => $previousRole,
                'to_role' => $newRole,
            ],
            'created_at' => now(),
        ]);

        SecurityAlert::dispatch('user_role_changed', [
            'title' => 'User role changed',
            'target_user_id' => $this->record->getKey(),
            'target_email' => $this->record->email,
            'target_username' => $this->record->username,
            'from_role' => $previousRole,
            'to_role' => $newRole,
            'actor_id' => AuthHelper::id(),
            'actor_email' => AuthHelper::user()?->email,
        ], $request);
    }
}

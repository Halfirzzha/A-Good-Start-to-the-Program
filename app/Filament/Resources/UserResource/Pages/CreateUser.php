<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Support\AuditLogWriter;
use App\Support\AuthHelper;
use App\Support\SecurityAlert;
use App\Support\SecurityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private ?string $selectedRole = null;

    protected function getFormActions(): array
    {
        if (! $this->canSubmit()) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! $this->canSubmit()) {
            return [];
        }

        $data = $this->filterByPermissions($data);

        $role = $data['role'] ?? null;
        if (! is_string($role) || ! UserResource::canAssignRoleName($role, AuthHelper::user())) {
            abort(403, __('ui.users.errors.role_assignment_denied'));
        }

        $this->selectedRole = $role;
        $data['created_by_type'] = 'admin';
        $data['created_by_admin_id'] = AuthHelper::id();

        if (empty($data['password'])) {
            $data['password'] = Str::random(64);
            $data['must_change_password'] = true;
        }

        if (empty($data['username'])) {
            $data['username'] = null;
        }

        $data['email_verified_at'] = null;

        if (empty($data['two_factor_enabled'])) {
            $data['two_factor_method'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterByPermissions(array $data): array
    {
        $originalKeys = array_keys($data);

        if (! UserResource::canManageAvatar()) {
            unset($data['avatar']);
        }

        if (! UserResource::canManageIdentity()) {
            unset(
                $data['name'],
                $data['email'],
                $data['username'],
                $data['position'],
                $data['role'],
                $data['phone_country_code'],
                $data['phone_number'],
            );
        }

        if (! UserResource::canManageSecurity()) {
            unset(
                $data['password'],
                $data['password_confirmation'],
                $data['must_change_password'],
                $data['password_expires_at'],
                $data['two_factor_enabled'],
                $data['two_factor_method'],
            );
        }

        if (! UserResource::canManageAccessStatus()) {
            unset(
                $data['account_status'],
                $data['blocked_until'],
                $data['blocked_reason'],
                $data['blocked_by'],
            );
        }

        $blocked = array_values(array_diff($originalKeys, array_keys($data)));
        if ($blocked !== []) {
            AuditLogWriter::writeAudit([
                'user_id' => AuthHelper::id(),
                'action' => 'unauthorized_field_update',
                'auditable_type' => \App\Models\User::class,
                'auditable_id' => null,
                'old_values' => null,
                'new_values' => null,
                'context' => [
                    'resource' => 'user',
                    'blocked_fields' => $blocked,
                    'operation' => 'create',
                ],
                'created_at' => now(),
            ]);
        }

        return $data;
    }

    private function canSubmit(): bool
    {
        return UserResource::canManageIdentity();
    }

    protected function afterCreate(): void
    {
        if (! $this->record || ! $this->selectedRole) {
            return;
        }

        $this->record->syncRoles([$this->selectedRole]);
        $this->record->forceFill([
            'role' => $this->selectedRole,
        ])->save();

        $this->recordRoleAssignment($this->selectedRole);
        $this->recordResourceCreate();

        $invite = UserInvitation::createFor($this->record, AuthHelper::user());
        $this->record->notify(new UserInvitationNotification(
            $invite['token'],
            $invite['invitation']->expires_at
        ));
    }

    private function recordRoleAssignment(string $role): void
    {
        if (! $this->record) {
            return;
        }

        $request = request();
        $requestId = SecurityService::requestId($request);
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => AuthHelper::id(),
            'action' => 'user_role_assigned',
            'auditable_type' => $this->record->getMorphClass(),
            'auditable_id' => $this->record->getKey(),
            'old_values' => null,
            'new_values' => ['role' => $role],
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
                'assigned_role' => $role,
            ],
            'created_at' => now(),
        ]);

        SecurityAlert::dispatch('user_role_assigned', [
            'title' => __('ui.users.alerts.role_assigned'),
            'target_user_id' => $this->record->getKey(),
            'target_email' => $this->record->email,
            'target_username' => $this->record->username,
            'assigned_role' => $role,
            'actor_id' => AuthHelper::id(),
            'actor_email' => AuthHelper::user()?->email,
        ], $request);
    }

    private function recordResourceCreate(): void
    {
        if (! $this->record || ! config('audit.enabled', true)) {
            return;
        }

        $request = request();
        $requestId = SecurityService::requestId($request);
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        $newValues = UserResource::sanitizeAuditValues([
            'id' => $this->record->getKey(),
            'name' => $this->record->name,
            'email' => $this->record->email,
            'username' => $this->record->username,
            'role' => $this->record->role,
            'account_status' => $this->record->account_status,
        ]);

        AuditLogWriter::writeAudit([
            'user_id' => AuthHelper::id(),
            'action' => 'user_resource_created',
            'auditable_type' => $this->record->getMorphClass(),
            'auditable_id' => $this->record->getKey(),
            'old_values' => null,
            'new_values' => $newValues,
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
                'resource' => 'user',
                'operation' => 'create',
            ],
            'created_at' => now(),
        ]);
    }
}

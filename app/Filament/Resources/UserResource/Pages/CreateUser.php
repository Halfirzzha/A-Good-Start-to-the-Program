<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Support\AuditLogWriter;
use App\Support\SecurityAlert;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private ?string $selectedRole = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = $data['role'] ?? null;
        if (! is_string($role) || ! UserResource::canAssignRoleName($role, auth()->user())) {
            abort(403, 'Role assignment denied.');
        }

        $this->selectedRole = $role;
        $data['created_by_type'] = 'admin';
        $data['created_by_admin_id'] = auth()->id();

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

        $invite = UserInvitation::createFor($this->record, auth()->user());
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
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => auth()->id(),
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
                'actor_id' => auth()->id(),
                'target_user_id' => $this->record->getKey(),
                'target_email' => $this->record->email,
                'target_username' => $this->record->username,
                'assigned_role' => $role,
            ],
            'created_at' => now(),
        ]);

        SecurityAlert::dispatch('user_role_assigned', [
            'title' => 'User role assigned',
            'target_user_id' => $this->record->getKey(),
            'target_email' => $this->record->email,
            'target_username' => $this->record->username,
            'assigned_role' => $role,
            'actor_id' => auth()->id(),
            'actor_email' => auth()->user()?->email,
        ], $request);
    }
}

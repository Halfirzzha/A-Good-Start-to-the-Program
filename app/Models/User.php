<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\Auditable;
use App\Models\UserLoginActivity;
use App\Models\UserPasswordHistory;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Support\AuditLogWriter;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\SystemSettings;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail, HasAvatar
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes, MustVerifyEmailTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'avatar',
        'position',
        'role',
        'account_status',
        'blocked_until',
        'blocked_reason',
        'blocked_by',
        'phone_country_code',
        'phone_number',
        'timezone',
        'locale',
        'must_change_password',
        'password_expires_at',
        'two_factor_enabled',
        'two_factor_method',
        'created_by_type',
        'created_by_admin_id',
        'deleted_by',
        'deleted_ip',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'security_stamp',
        'last_password_changed_user_agent',
        'last_login_user_agent',
        'last_failed_login_user_agent',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'security_stamp',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_status' => AccountStatus::class,
            'password_changed_at' => 'datetime',
            'password_expires_at' => 'datetime',
            'first_login_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_failed_login_at' => 'datetime',
            'locked_at' => 'datetime',
            'blocked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }

            if (empty($user->security_stamp)) {
                $user->security_stamp = Str::random(64);
            }
        });

        static::created(function (self $user): void {
            if (! self::createdViaFilamentUserCommand()) {
                return;
            }

            if (blank($user->username)) {
                $user->forceFill([
                    'username' => self::generateUsername($user),
                ])->save();
            }

            if (! Schema::hasTable('roles')) {
                return;
            }

            $roleName = (string) config('security.developer_role', 'developer');
            $guard = config('auth.defaults.guard', 'web');

            $role = Role::findOrCreate($roleName, $guard);

            $user->syncRoles([$role->name]);
            $user->forceFill([
                'role' => $role->name,
            ])->save();

            if (empty($user->email_verified_at)) {
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }
        });

        static::saving(function (self $user): void {
            if (! $user->exists || ! $user->isDirty('password')) {
                return;
            }

            $user->password_changed_at = now();
            $expiryDays = max(1, (int) config('security.password_expiry_days', 90));
            $user->password_expires_at = now()->addDays($expiryDays);

            $actorId = Auth::id();
            if ($actorId) {
                $user->password_changed_by = $actorId;
            }

            if (! app()->runningInConsole()) {
                $request = request();
                $user->last_password_changed_ip = $request?->ip();
                $user->last_password_changed_user_agent = $request
                    ? Str::limit((string) $request->userAgent(), 255, '')
                    : null;
            }

            $user->security_stamp = Str::random(64);
        });

        static::saved(function (self $user): void {
            if ($user->wasChanged('password')) {
                $user->recordPasswordHistory();
            }
        });
    }

    public function passwordHistories()
    {
        return $this->hasMany(UserPasswordHistory::class);
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(UserLoginActivity::class);
    }

    public function isActive(): bool
    {
        return $this->account_status === AccountStatus::Active;
    }

    public function isLocked(): bool
    {
        return filled($this->locked_at) || ($this->blocked_until?->isFuture() ?? false);
    }

    public function isAdmin(): bool
    {
        return $this->can('access_admin_panel');
    }

    public function getFilamentAvatarUrl(): ?string
    {
        $avatar = $this->avatar;
        if (! $avatar) {
            return $this->buildFallbackAvatar();
        }

        if (filter_var($avatar, FILTER_VALIDATE_URL) !== false) {
            return $avatar;
        }

        $disk = null;
        $path = $avatar;

        if (preg_match('/^([A-Za-z0-9_-]+):(.*)$/', $avatar, $matches) === 1) {
            $disk = $matches[1] ?: null;
            $path = $matches[2] ?: $avatar;
        }

        if (! str_contains($path, '/')) {
            $path = 'avatars/'.ltrim($path, '/');
        }

        $primary = (string) SystemSettings::getValue('storage.primary_disk', 'public');
        $fallback = (string) SystemSettings::getValue('storage.fallback_disk', 'public');

        $disk = $this->sanitizePublicDisk($disk)
            ?: $this->sanitizePublicDisk($primary)
            ?: $this->sanitizePublicDisk($fallback)
            ?: 'public';

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }
        } catch (\Throwable) {
            return $this->buildFallbackAvatar();
        }

        return Storage::disk($disk)->url($path);
    }

    private function sanitizePublicDisk(?string $disk): ?string
    {
        if (! $disk) {
            return null;
        }

        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config)) {
            return null;
        }

        if (($config['visibility'] ?? null) === 'public') {
            return $disk;
        }

        if (! empty($config['url'])) {
            return $disk;
        }

        return null;
    }

    private function buildFallbackAvatar(): string
    {
        $name = trim((string) ($this->name ?: $this->email ?: 'User'));
        $parts = array_filter(preg_split('/\s+/', $name) ?: []);
        $initials = '';
        $substr = function (string $value, int $start, int $length = 1): string {
            return function_exists('mb_substr')
                ? mb_substr($value, $start, $length)
                : substr($value, $start, $length);
        };

        $strlen = function (string $value): int {
            return function_exists('mb_strlen')
                ? mb_strlen($value)
                : strlen($value);
        };

        foreach ($parts as $part) {
            $initials .= $substr($part, 0, 1);
            if ($strlen($initials) >= 2) {
                break;
            }
        }
        $initials = $initials ?: 'U';

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='64' height='64' viewBox='0 0 64 64' role='img' aria-label='Avatar'>"
            ."<rect width='64' height='64' rx='32' fill='#1f2937'/>"
            ."<text x='50%' y='52%' text-anchor='middle' dominant-baseline='middle' font-family='Arial, sans-serif' font-size='24' fill='#ffffff'>"
            .htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
            ."</text></svg>";

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode($svg);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole((string) config('security.superadmin_role', 'super_admin'));
    }

    public function isDeveloper(): bool
    {
        return $this->hasRole((string) config('security.developer_role', 'developer'));
    }

    /**
     * Check if user has elevated privileges (Developer or SuperAdmin).
     * These users have full access with no UI restrictions.
     */
    public function hasElevatedPrivileges(): bool
    {
        return $this->isDeveloper() || $this->isSuperAdmin();
    }

    /**
     * Get the user's role rank from hierarchy.
     * Higher number = more privileges.
     */
    public function getRoleRank(): int
    {
        $hierarchy = config('security.role_hierarchy', []);
        $roleNames = $this->getRoleNames();

        if ($roleNames->isEmpty() && $this->role) {
            $roleNames = collect([$this->role]);
        }

        return $roleNames
            ->map(fn (string $role): int => $hierarchy[$role] ?? -1)
            ->max() ?? -1;
    }

    /**
     * Check if this user can manage another user based on role hierarchy.
     */
    public function canManageUser(self $target): bool
    {
        if ($this->isDeveloper()) {
            return true;
        }

        if ($target->isDeveloper()) {
            return false;
        }

        if ($target->isSuperAdmin() && ! $this->isDeveloper()) {
            return false;
        }

        return $this->getRoleRank() > $target->getRoleRank();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $reasons = $this->panelAccessReasons();

        if (empty($reasons)) {
            return true;
        }

        if ($this->shouldBypassPanelChecks()) {
            $this->logPanelAccessBypassed($panel, $reasons);
            return true;
        }

        $this->logPanelAccessDenied($panel, $reasons);
        return false;
    }

    public function rotateSecurityStamp(): void
    {
        $this->forceFill([
            'security_stamp' => Str::random(64),
        ])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function hasUsedPassword(string $plain, ?int $limit = null): bool
    {
        $limit ??= (int) config('security.password_history', 5);
        if ($limit <= 0) {
            return false;
        }

        $histories = $this->passwordHistories()
            ->latest('id')
            ->limit($limit)
            ->get(['password']);

        foreach ($histories as $history) {
            if (Hash::check($plain, $history->password)) {
                return true;
            }
        }

        return false;
    }

    protected function recordPasswordHistory(): void
    {
        if (empty($this->password)) {
            return;
        }

        $this->passwordHistories()->create([
            'password' => $this->password,
            'created_at' => now(),
        ]);

        $limit = (int) config('security.password_history', 5);
        if ($limit <= 0) {
            return;
        }

        $idsToKeep = $this->passwordHistories()
            ->latest('id')
            ->limit($limit)
            ->pluck('id');

        $this->passwordHistories()
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }

    private static function createdViaFilamentUserCommand(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        $commands = [
            'make:filament-user',
            'filament:make-user',
            'filament:user',
        ];

        foreach ($commands as $command) {
            if (in_array($command, $argv, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function panelAccessReasons(): array
    {
        $reasons = [];

        if (! $this->hasVerifiedEmail()) {
            $reasons[] = 'email_unverified';
        }

        if (blank($this->username)) {
            $reasons[] = 'username_missing';
        }

        if ($this->must_change_password) {
            $reasons[] = 'password_change_required';
        }

        if ($this->password_expires_at && $this->password_expires_at->isPast()) {
            $reasons[] = 'password_expired';
        }

        $blockedUntil = $this->blocked_until;
        $isTemporarilyBlocked = $blockedUntil && $blockedUntil->isFuture();
        $isStaleLock = $blockedUntil && $blockedUntil->isPast();

        if ($isTemporarilyBlocked) {
            $reasons[] = 'blocked_until';
        }

        if (! $isStaleLock && filled($this->locked_at)) {
            $reasons[] = 'locked';
        }

        if ($this->account_status !== AccountStatus::Active) {
            $reasons[] = 'account_inactive';
        }

        if (! $this->can('access_admin_panel')) {
            $reasons[] = 'missing_permission';
        }

        return $reasons;
    }

    private function shouldBypassPanelChecks(): bool
    {
        return $this->isDeveloper() && (bool) config('security.developer_bypass_validations', false);
    }

    private static function generateUsername(self $user): string
    {
        $base = '';

        if (is_string($user->email) && $user->email !== '') {
            $base = Str::before($user->email, '@');
        }

        if ($base === '' && is_string($user->name)) {
            $base = $user->name;
        }

        $base = Str::slug($base);

        if ($base === '') {
            $base = 'developer';
        }

        $base = Str::limit($base, 40, '');
        $candidate = $base;
        $suffix = 1;

        while (self::query()->where('username', $candidate)->exists()) {
            $suffixText = '-' . $suffix;
            $candidate = Str::limit($base, 50 - strlen($suffixText), '') . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function logPanelAccessDenied(Panel $panel, array $reasons): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $request = request();
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $this->getAuthIdentifier(),
            'identity' => $this->email ?? $this->username,
            'event' => 'panel_access_denied',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'panel' => $panel->getId(),
                'reasons' => $reasons,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $reasons
     */
    private function logPanelAccessBypassed(Panel $panel, array $reasons): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $request = request();
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeLoginActivity([
            'user_id' => $this->getAuthIdentifier(),
            'identity' => $this->email ?? $this->username,
            'event' => 'panel_access_bypassed',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'session_id' => $sessionId,
            'request_id' => $requestId,
            'context' => [
                'panel' => $panel->getId(),
                'reasons' => $reasons,
            ],
            'created_at' => now(),
        ]);
    }
}

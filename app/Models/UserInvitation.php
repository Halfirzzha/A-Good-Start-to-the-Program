<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserInvitation extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'created_by',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array{invitation: self, token: string}
     */
    public static function createFor(User $user, ?User $actor = null): array
    {
        $token = Str::random(64);
        $hash = self::hashToken($token);
        $expiresDays = (int) config('security.invitation_expires_days', 5);
        $expiresAt = $expiresDays > 0 ? now()->addDays($expiresDays) : null;

        self::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $invitation = self::create([
            'user_id' => $user->getKey(),
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'created_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);

        return [
            'invitation' => $invitation,
            'token' => $token,
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSettingVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'system_setting_versions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'system_setting_id',
        'action',
        'snapshot',
        'changed_keys',
        'actor_id',
        'request_id',
        'ip_address',
        'user_agent',
        'context',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'snapshot' => 'array',
        'changed_keys' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(SystemSetting::class, 'system_setting_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

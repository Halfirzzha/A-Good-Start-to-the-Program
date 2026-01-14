<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    protected $fillable = [
        'notification_id',
        'channel',
        'enabled',
        'provider',
        'from_name',
        'from_address',
        'reply_to',
        'chat_id',
        'sms_sender',
        'max_attempts',
        'retry_after_seconds',
        'scheduled_at',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'scheduled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'notification_id');
    }
}

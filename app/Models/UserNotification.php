<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'channel',
        'is_read',
        'read_at',
        'delivered_at',
        'metadata',
    ];

    protected $casts = [
        'is_read' => 'bool',
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

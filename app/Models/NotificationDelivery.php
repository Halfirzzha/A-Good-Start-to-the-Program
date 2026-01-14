<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'notification_type',
        'channel',
        'status',
        'attempts',
        'notifiable_type',
        'notifiable_id',
        'recipient',
        'summary',
        'data',
        'error_message',
        'ip_address',
        'user_agent',
        'device_type',
        'request_id',
        'idempotency_key',
        'queued_at',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'data' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function notificationMessage(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'notification_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_type',
        'channel',
        'status',
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
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}

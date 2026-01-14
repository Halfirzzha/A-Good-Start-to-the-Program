<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationMessage extends Model
{
    protected $fillable = [
        'title',
        'message',
        'category',
        'priority',
        'status',
        'target_all',
        'scheduled_at',
        'sent_at',
        'expires_at',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_all' => 'bool',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(NotificationTarget::class, 'notification_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class, 'notification_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'notification_id');
    }
}

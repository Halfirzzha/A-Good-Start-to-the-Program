<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLoginActivity extends Model
{
    protected $table = 'user_login_activities';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

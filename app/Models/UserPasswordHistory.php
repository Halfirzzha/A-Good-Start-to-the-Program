<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPasswordHistory extends Model
{
    protected $table = 'user_password_histories';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

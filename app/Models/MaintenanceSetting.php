<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use App\Support\MaintenanceService;

class MaintenanceSetting extends Model
{
    use Auditable;

    protected $table = 'maintenance_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enabled',
        'mode',
        'title',
        'summary',
        'note_html',
        'start_at',
        'end_at',
        'retry_after',
        'allow_roles',
        'allow_ips',
        'allow_paths',
        'deny_paths',
        'allow_routes',
        'deny_routes',
        'allow_api',
        'allow_developer_bypass',
        'updated_by',
        'updated_ip',
        'updated_user_agent',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'allow_api' => 'boolean',
        'allow_developer_bypass' => 'boolean',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'retry_after' => 'integer',
        'allow_roles' => 'array',
        'allow_ips' => 'array',
        'allow_paths' => 'array',
        'deny_paths' => 'array',
        'allow_routes' => 'array',
        'deny_routes' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            if (app()->runningInConsole()) {
                return;
            }

            $request = request();
            $userId = Auth::id();

            if ($userId) {
                $setting->updated_by = $userId;
            }

            if ($request) {
                $setting->updated_ip = $request->ip();
                $setting->updated_user_agent = substr((string) $request->userAgent(), 0, 255);
            }

            if ($setting->isDirty('note_html')) {
                $setting->note_html = MaintenanceService::sanitizeNote($setting->note_html);
            }
        });

        static::saved(function (): void {
            MaintenanceService::forget();
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

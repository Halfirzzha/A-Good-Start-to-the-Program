<?php

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditLogCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var array<string, mixed>
     */
    public array $logData;

    /**
     * Create a new event instance.
     */
    public function __construct(AuditLog $auditLog)
    {
        $this->logData = [
            'id' => $auditLog->id,
            'action' => $auditLog->action,
            'auditable_type' => class_basename($auditLog->auditable_type ?? ''),
            'auditable_id' => $auditLog->auditable_id,
            'user_id' => $auditLog->user_id,
            'user_name' => $auditLog->user?->name,
            'ip_address' => $auditLog->ip_address,
            'method' => $auditLog->method,
            'url' => $auditLog->url,
            'status_code' => $auditLog->status_code,
            'created_at' => $auditLog->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('audit.logs'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'log' => $this->logData,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'audit.created';
    }
}

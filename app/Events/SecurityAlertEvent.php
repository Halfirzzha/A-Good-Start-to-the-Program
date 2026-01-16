<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var array<string, mixed>
     */
    public array $alert;

    /**
     * @var string
     */
    public string $severity;

    /**
     * @var string|null
     */
    public ?string $userId;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $alert
     */
    public function __construct(array $alert, string $severity = 'warning', ?string $userId = null)
    {
        $this->alert = $alert;
        $this->severity = $severity;
        $this->userId = $userId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('security.alerts'),
        ];

        // Also broadcast to specific user if applicable
        if ($this->userId) {
            $channels[] = new PrivateChannel('user.'.$this->userId);
        }

        return $channels;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert' => $this->alert,
            'severity' => $this->severity,
            'user_id' => $this->userId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'security.alert';
    }
}

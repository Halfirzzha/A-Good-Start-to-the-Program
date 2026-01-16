<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaintenanceModeChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var bool
     */
    public bool $enabled;

    /**
     * @var string|null
     */
    public ?string $title;

    /**
     * @var string|null
     */
    public ?string $summary;

    /**
     * @var string|null
     */
    public ?string $startAt;

    /**
     * @var string|null
     */
    public ?string $endAt;

    /**
     * Create a new event instance.
     */
    public function __construct(
        bool $enabled,
        ?string $title = null,
        ?string $summary = null,
        ?string $startAt = null,
        ?string $endAt = null
    ) {
        $this->enabled = $enabled;
        $this->title = $title;
        $this->summary = $summary;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('maintenance'),
            new PrivateChannel('admin.notifications'),
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
            'enabled' => $this->enabled,
            'title' => $this->title,
            'summary' => $this->summary,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'maintenance.changed';
    }
}

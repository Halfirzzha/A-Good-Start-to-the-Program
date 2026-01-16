<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemSettingsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var string
     */
    public string $section;

    /**
     * @var array<string>
     */
    public array $changedKeys;

    /**
     * @var int|null
     */
    public ?int $updatedBy;

    /**
     * Create a new event instance.
     *
     * @param  array<string>  $changedKeys
     */
    public function __construct(string $section, array $changedKeys = [], ?int $updatedBy = null)
    {
        $this->section = $section;
        $this->changedKeys = $changedKeys;
        $this->updatedBy = $updatedBy;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.notifications'),
            new PrivateChannel('system.settings'),
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
            'section' => $this->section,
            'changed_keys' => $this->changedKeys,
            'updated_by' => $this->updatedBy,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'settings.updated';
    }
}

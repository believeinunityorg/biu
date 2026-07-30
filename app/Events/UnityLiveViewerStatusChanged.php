<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Immediate Reverb broadcast so Unity Live viewers see end/go-live without queue delay.
 */
class UnityLiveViewerStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channelName,
        public string $reason,
        public array $payload,
    ) {}

    /**
     * Broadcast after the DB transaction commits when the host action runs in a transaction.
     */
    public bool $broadcastAfterCommit = true;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel($this->channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'viewer.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

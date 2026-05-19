<?php

namespace App\Events\Patrol;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatrolSessionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $session
     */
    public function __construct(
        public string $patrolSessionId,
        public array $session,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('patrol.monitoring'),
            new PrivateChannel('patrol.session.'.$this->patrolSessionId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PatrolSessionStarted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'patrol_session_id' => $this->patrolSessionId,
            'session' => $this->session,
        ];
    }
}

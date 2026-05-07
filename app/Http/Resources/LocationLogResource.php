<?php

namespace App\Http\Resources;

use App\Models\LocationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LocationLog */
class LocationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patrol_session_id' => $this->patrol_session_id,
            'user_id' => $this->user_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy' => $this->accuracy,
            'timestamp' => $this->timestamp,
            'server_received_at' => $this->server_received_at,
            'source' => $this->source,
            'tracking_state' => $this->tracking_state,
            'speed' => $this->speed,
            'heading' => $this->heading,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', function (): ?array {
                if ($this->user === null) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }, null),
            'patrol_session' => $this->whenLoaded('patrolSession', function (): ?array {
                if ($this->patrolSession === null) {
                    return null;
                }

                return [
                    'id' => $this->patrolSession->id,
                    'status' => $this->patrolSession->status,
                    'started_at' => $this->patrolSession->started_at,
                    'ended_at' => $this->patrolSession->ended_at,
                ];
            }, null),
        ];
    }
}

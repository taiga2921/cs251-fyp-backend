<?php

namespace App\Http\Resources;

use App\Models\PatrolRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatrolRoute */
class PatrolRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patrol_session_id' => $this->patrol_session_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy' => $this->accuracy,
            'altitude' => $this->altitude,
            'recorded_at' => $this->recorded_at,
            'created_at' => $this->created_at,
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

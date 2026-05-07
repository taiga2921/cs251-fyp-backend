<?php

namespace App\Http\Resources;

use App\Models\CheckpointEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CheckpointEvent */
class CheckpointEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patrol_session_id' => $this->patrol_session_id,
            'checkpoint_id' => $this->checkpoint_id,
            'entered_at' => $this->entered_at,
            'exited_at' => $this->exited_at,
            'detected_at' => $this->detected_at,
            'processed_at' => $this->processed_at,
            'detection_type' => $this->detection_type,
            'confidence_score' => $this->confidence_score,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'checkpoint' => $this->whenLoaded('checkpoint', function (): ?array {
                if ($this->checkpoint === null) {
                    return null;
                }

                return [
                    'id' => $this->checkpoint->id,
                    'name' => $this->checkpoint->name,
                    'latitude' => $this->checkpoint->latitude,
                    'longitude' => $this->checkpoint->longitude,
                    'radius' => $this->checkpoint->radius,
                ];
            }, null),
            'patrol_session' => $this->whenLoaded('patrolSession', function (): ?array {
                if ($this->patrolSession === null) {
                    return null;
                }

                return [
                    'id' => $this->patrolSession->id,
                    'user_id' => $this->patrolSession->user_id,
                    'zone_id' => $this->patrolSession->zone_id,
                    'status' => $this->patrolSession->status,
                    'started_at' => $this->patrolSession->started_at,
                    'ended_at' => $this->patrolSession->ended_at,
                ];
            }, null),
            'metric' => $this->whenLoaded('metric', function (): mixed {
                return $this->metric === null
                    ? null
                    : (new CheckpointEventMetricResource($this->metric))->resolve();
            }),
        ];
    }
}

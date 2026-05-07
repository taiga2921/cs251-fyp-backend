<?php

namespace App\Http\Resources;

use App\Models\CheckpointEventMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CheckpointEventMetric */
class CheckpointEventMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'checkpoint_event_id' => $this->checkpoint_event_id,
            'distance_score' => $this->distance_score,
            'accuracy_score' => $this->accuracy_score,
            'time_score' => $this->time_score,
            'stability_score' => $this->stability_score,
            'gap_factor' => $this->gap_factor,
            'integrity_factor' => $this->integrity_factor,
            'calculated_confidence_score' => $this->calculatedConfidenceScore(),
            'created_at' => $this->created_at,
            'checkpoint_event' => $this->whenLoaded(
                'checkpointEvent',
                fn (): CheckpointEventResource => new CheckpointEventResource($this->checkpointEvent)
            ),
        ];
    }

    protected function calculatedConfidenceScore(): float
    {
        $distance = (float) $this->distance_score;
        $accuracy = (float) $this->accuracy_score;
        $time = (float) $this->time_score;
        $stability = (float) $this->stability_score;
        $gap = (float) $this->gap_factor;
        $integrity = (float) $this->integrity_factor;

        $base = (0.3 * $distance)
            + (0.25 * $accuracy)
            + (0.25 * $time)
            + (0.2 * $stability);

        return round($base * $gap * $integrity, 2);
    }
}

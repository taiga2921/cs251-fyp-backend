<?php

namespace App\Http\Resources;

use App\Models\Checkpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Checkpoint */
class CheckpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zone_id' => $this->zone_id,
            'name' => $this->name,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius' => $this->radius,
            'location_type' => $this->location_type,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'zone' => $this->whenLoaded('zone', function (): ?array {
                if ($this->zone === null) {
                    return null;
                }

                return [
                    'id' => $this->zone->id,
                    'name' => $this->zone->name,
                ];
            }, null),
        ];
    }
}

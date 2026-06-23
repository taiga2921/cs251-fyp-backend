<?php

namespace App\Http\Resources;

use App\Models\AnprEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnprEvent */
class AnprEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'camera_id' => $this->camera_id,
            'blockchain_record_id' => $this->blockchain_record_id,
            'plate_number' => $this->plate_number,
            'confidence' => $this->confidence,
            'detection_time' => $this->detection_time,
            'is_flagged' => $this->is_flagged,
            'is_valid' => $this->is_valid,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'vehicle' => AnprVehicleResource::make($this->whenLoaded('vehicle')),
            'camera' => AnprCameraResource::make($this->whenLoaded('camera')),
            'images' => AnprImageResource::collection($this->whenLoaded('images')),
            'images_count' => $this->when(
                array_key_exists('images_count', $this->resource->getAttributes()),
                fn () => (int) $this->images_count
            ),
        ];
    }
}

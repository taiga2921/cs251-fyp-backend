<?php

namespace App\Http\Resources;

use App\Models\Camera;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Camera */
class AnprCameraResource extends JsonResource
{
    /**
     * Safe camera fields for ANPR monitoring responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'ip_address' => $this->ip_address,
            'port' => $this->port,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'resolution_width' => $this->resolution_width,
            'resolution_height' => $this->resolution_height,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

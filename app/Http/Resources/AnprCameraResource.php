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
            'is_active' => (bool) $this->is_active,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}

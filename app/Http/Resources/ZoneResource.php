<?php

namespace App\Http\Resources;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Zone */
class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'checkpoints_count' => $this->checkpoints_count ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator' => $this->created_by === null
                ? null
                : [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ],
        ];
    }
}

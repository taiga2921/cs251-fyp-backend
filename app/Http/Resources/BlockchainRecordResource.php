<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'hash' => $this->hash,
            'network' => $this->network,
            'environment' => $this->environment,
            'tx_hash' => $this->tx_hash,
            'block_number' => $this->block_number,
            'status' => $this->status,
            'retry_count' => $this->retry_count,
            'error_message' => $this->error_message,
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'confirmed_at' => $this->confirmed_at,
        ];
    }
}

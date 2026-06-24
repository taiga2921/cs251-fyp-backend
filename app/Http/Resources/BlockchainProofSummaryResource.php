<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainProofSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'proof_type' => $this->proof_type,
            'network' => $this->network,
            'environment' => $this->environment,
            'status' => $this->status,
            'tx_hash' => $this->tx_hash,
            'block_number' => $this->block_number,
            'confirmations' => $this->confirmations,
            'submitted_at' => $this->submitted_at,
            'confirmed_at' => $this->confirmed_at,
            'last_error' => $this->last_error,
        ];
    }
}

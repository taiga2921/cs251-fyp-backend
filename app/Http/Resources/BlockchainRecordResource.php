<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainRecordResource extends JsonResource
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
            'canonical_version' => $this->canonical_version,
            'hash_algorithm' => $this->hash_algorithm,
            'record_hash' => $this->record_hash,
            'payload_summary' => $this->payload_summary,
            'network' => $this->network,
            'environment' => $this->environment,
            'chain_id' => $this->chain_id,
            'contract_address' => $this->contract_address,
            'tx_hash' => $this->tx_hash,
            'block_number' => $this->block_number,
            'confirmations' => $this->confirmations,
            'status' => $this->status,
            'retry_count' => $this->retry_count,
            'last_error' => $this->last_error,
            'submitted_at' => $this->submitted_at,
            'confirmed_at' => $this->confirmed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'jobs' => BlockchainJobResource::collection($this->whenLoaded('jobs')),
            'verifications' => BlockchainVerificationResource::collection($this->whenLoaded('verifications')),
        ];
    }
}

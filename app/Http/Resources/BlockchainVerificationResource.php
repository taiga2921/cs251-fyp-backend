<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blockchain_record_id' => $this->blockchain_record_id,
            'verified_by' => $this->verified_by,
            'verification_type' => $this->verification_type,
            'stored_hash' => $this->stored_hash,
            'recomputed_hash' => $this->recomputed_hash,
            'onchain_hash' => $this->onchain_hash,
            'onchain_found' => $this->onchain_found,
            'result' => $this->result,
            'error_message' => $this->error_message,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
            'verified_by_user' => $this->whenLoaded('verifiedBy', function (): ?array {
                if ($this->verifiedBy === null) {
                    return null;
                }

                return [
                    'id' => $this->verifiedBy->id,
                    'name' => $this->verifiedBy->name,
                ];
            }),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\PatrolSession;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatrolSession */
class PatrolSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'started_at' => ApiDateTime::format($this->started_at),
            'ended_at' => ApiDateTime::format($this->ended_at),
            'created_at' => ApiDateTime::format($this->created_at),
            'updated_at' => ApiDateTime::format($this->updated_at),
            'user' => $this->whenLoaded('user', function (): ?array {
                if ($this->user === null) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }, null),
            'zone' => $this->whenLoaded('zone', function (): ?array {
                if ($this->zone === null) {
                    return null;
                }

                return [
                    'id' => $this->zone->id,
                    'name' => $this->zone->name,
                ];
            }, null),
            'blockchain_record' => $this->whenLoaded('blockchainRecord', function (): ?array {
                if ($this->blockchainRecord === null) {
                    return null;
                }

                return [
                    'id' => $this->blockchainRecord->id,
                    'transaction_hash' => $this->blockchainRecord->tx_hash,
                    'recorded_at' => ApiDateTime::format(
                        $this->blockchainRecord->confirmed_at
                            ?? $this->blockchainRecord->submitted_at
                            ?? $this->blockchainRecord->created_at
                    ),
                ];
            }, null),
        ];
    }
}

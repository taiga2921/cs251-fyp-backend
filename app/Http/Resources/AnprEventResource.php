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
        $imageProofs = $this->relationLoaded('images')
            ? collect($this->images)
                ->filter(fn ($image) => $image->relationLoaded('blockchainRecord') && $image->blockchainRecord !== null)
                ->map(fn ($image) => BlockchainProofSummaryResource::make($image->blockchainRecord))
                ->values()
            : collect();

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
            'blockchain_proof' => BlockchainProofSummaryResource::make($this->whenLoaded('blockchainRecord')),
            'image_blockchain_proofs' => $this->when(
                $this->relationLoaded('images'),
                $imageProofs->isNotEmpty() ? $imageProofs : null
            ),
            'image_blockchain_proof_summary' => $this->when(
                $this->relationLoaded('images'),
                $this->buildImageBlockchainProofSummary($imageProofs)
            ),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BlockchainProofSummaryResource>  $imageProofs
     * @return array<string, mixed>|null
     */
    private function buildImageBlockchainProofSummary($imageProofs): ?array
    {
        if ($imageProofs->isEmpty()) {
            return null;
        }

        $statuses = $imageProofs
            ->map(fn ($proof) => $proof->resource->status ?? null)
            ->filter()
            ->values();

        return [
            'count' => $imageProofs->count(),
            'statuses' => $statuses->unique()->values()->all(),
            'confirmed_count' => $statuses->filter(fn ($status) => $status === 'confirmed')->count(),
        ];
    }
}

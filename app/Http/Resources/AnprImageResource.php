<?php

namespace App\Http\Resources;

use App\Models\AnprImage;
use App\Services\Anpr\AnprImageFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnprImage */
class AnprImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fileUrl = $this->resolveFileUrl();

        return [
            'id' => $this->id,
            'anpr_event_id' => $this->anpr_event_id,
            'image_type' => $this->image_type,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'resolution' => $this->resolution,
            'expires_at' => $this->expires_at,
            'url' => $fileUrl,
            'image_url' => $fileUrl,
            'blockchain_proof' => BlockchainProofSummaryResource::make($this->whenLoaded('blockchainRecord')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveFileUrl(): ?string
    {
        if (! is_string($this->file_path) || trim($this->file_path) === '') {
            return null;
        }

        $service = app(AnprImageFileService::class);

        if ($service->resolveAbsolutePath($this->file_path) === null) {
            return null;
        }

        return url('/api/anpr-images/'.$this->id.'/file');
    }
}

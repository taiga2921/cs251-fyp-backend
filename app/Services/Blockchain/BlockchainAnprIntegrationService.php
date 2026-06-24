<?php

namespace App\Services\Blockchain;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\BlockchainRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class BlockchainAnprIntegrationService
{
    public function __construct(
        private readonly BlockchainRecordService $recordService,
        private readonly BlockchainRetryService $retryService,
    ) {}

    public function anchorEventCreation(AnprEvent $event): ?BlockchainRecord
    {
        if (! config('blockchain.enabled')) {
            return null;
        }

        return $this->createProofSafely($event, 'entity_created');
    }

    public function anchorImageEvidence(AnprImage $image): ?BlockchainRecord
    {
        if (! config('blockchain.enabled')) {
            return null;
        }

        return $this->createProofSafely($image, 'evidence_file');
    }

    private function createProofSafely(AnprEvent|AnprImage $entity, string $proofType): ?BlockchainRecord
    {
        try {
            return $this->recordService->createForEntity($entity, $proofType);
        } catch (Throwable $exception) {
            Log::warning('Automatic blockchain proof creation failed for ANPR entity.', [
                'entity_type' => $entity instanceof AnprEvent ? 'anpr_event' : 'anpr_image',
                'entity_id' => (string) $entity->getKey(),
                'proof_type' => $proofType,
                'error' => $this->retryService->sanitizeError($exception->getMessage()),
            ]);

            return null;
        }
    }
}

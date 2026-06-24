<?php

namespace App\Services\Blockchain;

use App\Models\AnprEvent;
use App\Support\BlockchainCanonicalJson;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class BlockchainHashService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     canonical_version: string,
     *     hash_algorithm: string,
     *     canonical_payload: array<string, mixed>,
     *     canonical_json: string,
     *     record_hash: string
     * }
     */
    public function hashPayload(array $payload): array
    {
        $canonicalVersion = (string) config('blockchain.canonical_version', 'v1');
        $hashAlgorithm = (string) config('blockchain.hash_algorithm', 'sha256');

        if ($hashAlgorithm !== 'sha256') {
            throw new InvalidArgumentException(
                "Unsupported blockchain hash algorithm: {$hashAlgorithm}"
            );
        }

        $canonicalPayload = BlockchainCanonicalJson::normalize($payload);
        if (! is_array($canonicalPayload)) {
            throw new InvalidArgumentException('Canonical payload must normalize to an array.');
        }

        $canonicalJson = BlockchainCanonicalJson::encode($payload);
        $recordHash = hash('sha256', $canonicalJson);

        return [
            'canonical_version' => $canonicalVersion,
            'hash_algorithm' => $hashAlgorithm,
            'canonical_payload' => $canonicalPayload,
            'canonical_json' => $canonicalJson,
            'record_hash' => $recordHash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCanonicalPayloadForEntity(Model $entity, string $proofType = 'entity_created'): array
    {
        if ($entity instanceof AnprEvent) {
            return $this->buildAnprEventPayload($entity, $proofType);
        }

        throw new InvalidArgumentException(
            'Unsupported entity class for blockchain hashing: '.$entity::class
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAnprEventPayload(AnprEvent $event, string $proofType = 'entity_created'): array
    {
        return [
            'entity_type' => 'anpr_event',
            'entity_id' => (string) $event->id,
            'proof_type' => $proofType,
            'camera_id' => (string) $event->camera_id,
            'plate_number' => (string) $event->plate_number,
            'confidence' => number_format((float) $event->confidence, 4, '.', ''),
            'detection_time' => BlockchainCanonicalJson::normalize($event->detection_time),
            'is_flagged' => (bool) $event->is_flagged,
            'is_valid' => (bool) $event->is_valid,
        ];
    }

    /**
     * @return array{
     *     canonical_version: string,
     *     hash_algorithm: string,
     *     canonical_payload: array<string, mixed>,
     *     canonical_json: string,
     *     record_hash: string
     * }
     */
    public function hashEntity(Model $entity, string $proofType = 'entity_created'): array
    {
        return $this->hashPayload(
            $this->buildCanonicalPayloadForEntity($entity, $proofType)
        );
    }
}

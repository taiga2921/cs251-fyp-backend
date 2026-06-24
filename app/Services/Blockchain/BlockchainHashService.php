<?php

namespace App\Services\Blockchain;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Services\Anpr\AnprImageFileService;
use App\Support\BlockchainCanonicalJson;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class BlockchainHashService
{
    public function __construct(
        private readonly AnprImageFileService $imageFileService,
    ) {}
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

        if ($entity instanceof AnprImage) {
            return $this->buildAnprImagePayload($entity, $proofType);
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
     * @return array<string, mixed>
     */
    public function buildAnprImagePayload(AnprImage $image, string $proofType = 'evidence_file'): array
    {
        $evidence = $this->resolveImageEvidenceMetadata($image);

        return [
            'entity_type' => 'anpr_image',
            'entity_id' => (string) $image->id,
            'proof_type' => $proofType,
            'anpr_event_id' => (string) $image->anpr_event_id,
            'image_type' => (string) $image->image_type,
            'file_path' => $evidence['relative_path'],
            'file_sha256' => $evidence['file_sha256'],
            'file_size' => $evidence['file_size'],
            'resolution' => $evidence['resolution'],
            'evidence_hash_source' => $evidence['evidence_hash_source'],
        ];
    }

    /**
     * @return array{
     *     relative_path: ?string,
     *     file_sha256: ?string,
     *     file_size: ?int,
     *     resolution: ?string,
     *     evidence_hash_source: 'file'|'metadata'
     * }
     */
    public function resolveImageEvidenceMetadata(AnprImage $image): array
    {
        $relativePath = $this->normalizeRelativeFilePath($image->file_path);
        $fileSize = is_numeric($image->file_size) ? (int) $image->file_size : null;
        $resolution = is_string($image->resolution) && trim($image->resolution) !== ''
            ? trim($image->resolution)
            : null;

        $absolutePath = is_string($image->file_path) && $image->file_path !== ''
            ? $this->imageFileService->resolveAbsolutePath($image->file_path)
            : null;

        if ($absolutePath !== null) {
            return [
                'relative_path' => $relativePath,
                'file_sha256' => strtolower(hash_file('sha256', $absolutePath)),
                'file_size' => $fileSize ?? (int) filesize($absolutePath),
                'resolution' => $resolution,
                'evidence_hash_source' => 'file',
            ];
        }

        return [
            'relative_path' => $relativePath,
            'file_sha256' => null,
            'file_size' => $fileSize,
            'resolution' => $resolution,
            'evidence_hash_source' => 'metadata',
        ];
    }

    private function normalizeRelativeFilePath(mixed $filePath): ?string
    {
        if (! is_string($filePath) || trim($filePath) === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', trim($filePath));

        if ($this->isAbsolutePath($normalized) || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
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

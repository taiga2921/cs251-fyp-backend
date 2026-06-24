<?php

namespace App\Services\Blockchain;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Models\BlockchainVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

class BlockchainVerificationService
{
    private const VERIFICATION_TYPES = [
        'manual',
        'scheduled',
        'api',
        'system',
    ];

    public function __construct(
        private readonly BlockchainHashService $hashService,
        private readonly EthereumRpcClient $ethereumRpcClient,
        private readonly BlockchainRetryService $retryService,
    ) {}

    public function verify(
        BlockchainRecord $record,
        string $verificationType = 'manual',
        ?User $verifiedBy = null,
    ): BlockchainVerification {
        $this->assertValidVerificationType($verificationType);

        $storedHash = $this->normalizeStoredHash((string) $record->record_hash);

        $job = BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => 'verify',
            'status' => 'processing',
            'attempts' => 1,
            'max_attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            if (! $record->isConfirmed()) {
                return $this->persistVerification(
                    $record,
                    $job,
                    $verificationType,
                    $verifiedBy,
                    [
                        'stored_hash' => $storedHash,
                        'recomputed_hash' => null,
                        'onchain_hash' => null,
                        'onchain_found' => null,
                        'result' => 'pending',
                        'error_message' => null,
                    ],
                    jobSucceeded: true,
                );
            }

            $entity = $this->resolveEntity($record);

            if ($entity === null) {
                return $this->persistVerification(
                    $record,
                    $job,
                    $verificationType,
                    $verifiedBy,
                    [
                        'stored_hash' => $storedHash,
                        'recomputed_hash' => null,
                        'onchain_hash' => null,
                        'onchain_found' => null,
                        'result' => 'failed',
                        'error_message' => $this->entityResolutionErrorMessage($record),
                    ],
                    jobSucceeded: false,
                );
            }

            $hashResult = $this->hashService->hashEntity($entity, (string) $record->proof_type);
            $recomputedHash = $this->normalizeStoredHash($hashResult['record_hash']);

            if ($recomputedHash !== $storedHash) {
                return $this->persistVerification(
                    $record,
                    $job,
                    $verificationType,
                    $verifiedBy,
                    [
                        'stored_hash' => $storedHash,
                        'recomputed_hash' => $recomputedHash,
                        'onchain_hash' => null,
                        'onchain_found' => null,
                        'result' => 'tampered',
                        'error_message' => null,
                    ],
                    jobSucceeded: true,
                );
            }

            $onchainFound = $this->ethereumRpcClient->verifyHash(
                $storedHash,
                $record->contract_address,
            );

            if ($onchainFound) {
                return $this->persistVerification(
                    $record,
                    $job,
                    $verificationType,
                    $verifiedBy,
                    [
                        'stored_hash' => $storedHash,
                        'recomputed_hash' => $recomputedHash,
                        'onchain_hash' => $storedHash,
                        'onchain_found' => true,
                        'result' => 'valid',
                        'error_message' => null,
                    ],
                    jobSucceeded: true,
                );
            }

            return $this->persistVerification(
                $record,
                $job,
                $verificationType,
                $verifiedBy,
                [
                    'stored_hash' => $storedHash,
                    'recomputed_hash' => $recomputedHash,
                    'onchain_hash' => null,
                    'onchain_found' => false,
                    'result' => 'onchain_missing',
                    'error_message' => null,
                ],
                jobSucceeded: true,
            );
        } catch (Throwable $exception) {
            return $this->persistVerification(
                $record,
                $job,
                $verificationType,
                $verifiedBy,
                [
                    'stored_hash' => $storedHash,
                    'recomputed_hash' => null,
                    'onchain_hash' => null,
                    'onchain_found' => null,
                    'result' => 'failed',
                    'error_message' => $this->retryService->sanitizeError($exception->getMessage()),
                ],
                jobSucceeded: false,
            );
        }
    }

    private function resolveEntity(BlockchainRecord $record): ?Model
    {
        return match ($record->entity_type) {
            'anpr_event' => AnprEvent::query()->find($record->entity_id),
            'anpr_image' => AnprImage::query()->find($record->entity_id),
            default => null,
        };
    }

    private function assertValidVerificationType(string $verificationType): void
    {
        if (! in_array($verificationType, self::VERIFICATION_TYPES, true)) {
            throw new InvalidArgumentException('Invalid blockchain verification type.');
        }
    }

    private function entityResolutionErrorMessage(BlockchainRecord $record): string
    {
        if (! in_array($record->entity_type, ['anpr_event', 'anpr_image'], true)) {
            return 'Unsupported blockchain entity type for verification: '.$record->entity_type;
        }

        return 'Source entity no longer exists for blockchain verification.';
    }

    private function normalizeStoredHash(string $recordHash): string
    {
        $normalized = strtolower(trim($recordHash));

        if (str_starts_with($normalized, '0x')) {
            $normalized = substr($normalized, 2);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            throw new InvalidArgumentException('Blockchain record hash must be a 64-character lowercase hex value.');
        }

        return $normalized;
    }

    /**
     * @param  array{
     *     stored_hash: string,
     *     recomputed_hash: ?string,
     *     onchain_hash: ?string,
     *     onchain_found: ?bool,
     *     result: string,
     *     error_message: ?string
     * }  $payload
     */
    private function persistVerification(
        BlockchainRecord $record,
        BlockchainJob $job,
        string $verificationType,
        ?User $verifiedBy,
        array $payload,
        bool $jobSucceeded,
    ): BlockchainVerification {
        $verification = BlockchainVerification::query()->create([
            'blockchain_record_id' => $record->id,
            'verified_by' => $verifiedBy?->id,
            'verification_type' => $verificationType,
            'stored_hash' => $payload['stored_hash'],
            'recomputed_hash' => $payload['recomputed_hash'],
            'onchain_hash' => $payload['onchain_hash'],
            'onchain_found' => $payload['onchain_found'],
            'result' => $payload['result'],
            'error_message' => $payload['error_message'],
            'verified_at' => now(),
        ]);

        $job->update([
            'status' => $jobSucceeded ? 'success' : 'failed',
            'finished_at' => now(),
            'last_error' => $payload['error_message'],
        ]);

        return $verification->load('verifiedBy');
    }
}

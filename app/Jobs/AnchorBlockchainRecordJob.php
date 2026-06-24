<?php

namespace App\Jobs;

use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainRetryService;
use App\Services\Blockchain\BlockchainSubmittedRecordRefreshService;
use App\Services\Blockchain\EthereumRpcClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class AnchorBlockchainRecordJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $blockchainRecordId,
        public readonly bool $isRetryAttempt = false,
        public readonly ?string $expectedBlockchainJobId = null,
    ) {}

    public function handle(
        EthereumRpcClient $ethereumRpcClient,
        BlockchainRetryService $retryService,
        BlockchainSubmittedRecordRefreshService $refreshService,
    ): void {
        $record = BlockchainRecord::query()->find($this->blockchainRecordId);

        if ($record === null) {
            return;
        }

        if ($record->isConfirmed()) {
            if ($this->isRetryAttempt && $this->expectedBlockchainJobId !== null) {
                $retryService->markExpectedRetryJobCancelledForRecord(
                    $this->expectedBlockchainJobId,
                    $record,
                    BlockchainRetryService::ACTIVE_RECORD_RETRY_REASON,
                );
            }

            return;
        }

        if ($this->isRetryAttempt && $this->expectedBlockchainJobId !== null) {
            $queuedJob = BlockchainJob::query()->find($this->expectedBlockchainJobId);
            $staleReason = $retryService->staleRetryReason($record, $queuedJob);

            if ($staleReason !== null) {
                $retryService->markExpectedRetryJobCancelledForRecord(
                    $this->expectedBlockchainJobId,
                    $record,
                    $staleReason,
                );

                return;
            }

            $blockchainJob = $this->activateQueuedRetryJob($queuedJob, $retryService);
            $attemptNumber = (int) $blockchainJob->attempts;
        } else {
            $attemptNumber = max(1, (int) $record->retry_count + 1);
            $blockchainJob = $this->createJobRow($record, $attemptNumber, $retryService);
        }

        try {
            if (is_string($record->tx_hash) && $record->tx_hash !== '') {
                $refreshService->refreshSubmittedRecord($record, submittedStatusOnly: false);
                $this->markAnchorJobSuccess($blockchainJob);

                return;
            }

            $record->markAsProcessing();

            $txHash = $ethereumRpcClient->storeHash(
                $record->record_hash,
                $record->contract_address
            );

            $record->markAsSubmitted($txHash);

            $this->confirmFromReceipt($record, $ethereumRpcClient, $retryService, $blockchainJob, $txHash, null, $refreshService);
        } catch (Throwable $exception) {
            $this->handleFailure($record, $blockchainJob, $exception, $retryService, $attemptNumber);
        }
    }

    private function activateQueuedRetryJob(
        BlockchainJob $queuedJob,
        BlockchainRetryService $retryService,
    ): BlockchainJob {
        $queuedJob->update([
            'status' => 'processing',
            'max_attempts' => $retryService->maxAttempts(),
            'started_at' => now(),
            'finished_at' => null,
            'last_error' => null,
            'next_attempt_at' => null,
        ]);

        return $queuedJob->refresh();
    }

    private function createJobRow(
        BlockchainRecord $record,
        int $attemptNumber,
        BlockchainRetryService $retryService,
    ): BlockchainJob {
        if (! $this->isRetryAttempt) {
            $existingAnchorJob = BlockchainJob::query()
                ->where('blockchain_record_id', $record->id)
                ->where('job_type', 'anchor')
                ->whereIn('status', ['queued', 'processing'])
                ->latest('created_at')
                ->first();

            if ($existingAnchorJob !== null) {
                $existingAnchorJob->update([
                    'status' => 'processing',
                    'attempts' => $attemptNumber,
                    'max_attempts' => $retryService->maxAttempts(),
                    'started_at' => now(),
                    'finished_at' => null,
                    'last_error' => null,
                    'next_attempt_at' => null,
                ]);

                return $existingAnchorJob->refresh();
            }
        }

        if ($this->isRetryAttempt) {
            $existingRetryJob = BlockchainJob::query()
                ->where('blockchain_record_id', $record->id)
                ->where('job_type', 'retry_anchor')
                ->whereIn('status', ['queued', 'processing'])
                ->latest('created_at')
                ->first();

            if ($existingRetryJob !== null) {
                $existingRetryJob->update([
                    'status' => 'processing',
                    'attempts' => $attemptNumber,
                    'max_attempts' => $retryService->maxAttempts(),
                    'started_at' => now(),
                    'finished_at' => null,
                    'last_error' => null,
                    'next_attempt_at' => null,
                ]);

                return $existingRetryJob->refresh();
            }
        }

        return BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => $this->isRetryAttempt ? 'retry_anchor' : 'anchor',
            'status' => 'processing',
            'attempts' => $attemptNumber,
            'max_attempts' => $retryService->maxAttempts(),
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $receipt
     *
     * @throws RuntimeException
     */
    private function confirmFromReceipt(
        BlockchainRecord $record,
        EthereumRpcClient $ethereumRpcClient,
        BlockchainRetryService $retryService,
        BlockchainJob $blockchainJob,
        string $txHash,
        ?array $receipt = null,
        ?BlockchainSubmittedRecordRefreshService $refreshService = null,
    ): void {
        $receipt ??= $ethereumRpcClient->transactionReceipt($txHash);

        if ($receipt === null) {
            $record->update([
                'status' => 'submitted',
                'tx_hash' => $txHash,
                'submitted_at' => $record->submitted_at ?? now(),
                'last_error' => $retryService->sanitizeError(
                    BlockchainSubmittedRecordRefreshService::MSG_RECEIPT_PENDING
                ),
            ]);

            $refreshService?->scheduleRefresh($record->fresh(), 1);
            $this->markAnchorJobSuccess($blockchainJob);

            return;
        }

        if (! $ethereumRpcClient->receiptIndicatesSuccess($receipt)) {
            throw new RuntimeException(BlockchainSubmittedRecordRefreshService::MSG_RECEIPT_FAILED);
        }

        $blockNumber = $ethereumRpcClient->hexQuantityToInt((string) $receipt['blockNumber']);
        $confirmations = $ethereumRpcClient->confirmationsForReceipt($receipt);
        $requiredConfirmations = $ethereumRpcClient->requiredConfirmationBlocks();

        if ($confirmations >= $requiredConfirmations) {
            $record->markAsConfirmed($txHash, $blockNumber, $confirmations);
        } else {
            $record->update([
                'tx_hash' => $txHash,
                'block_number' => $blockNumber,
                'confirmations' => $confirmations,
                'status' => 'submitted',
                'submitted_at' => $record->submitted_at ?? now(),
                'last_error' => $retryService->sanitizeError(
                    BlockchainSubmittedRecordRefreshService::MSG_INSUFFICIENT_CONFIRMATIONS
                ),
            ]);

            $refreshService?->scheduleRefresh($record->fresh(), 1);
        }

        $this->markAnchorJobSuccess($blockchainJob);
    }

    private function markAnchorJobSuccess(BlockchainJob $blockchainJob): void
    {
        $blockchainJob->update([
            'status' => 'success',
            'finished_at' => now(),
            'last_error' => null,
            'next_attempt_at' => null,
        ]);
    }

    private function handleFailure(
        BlockchainRecord $record,
        BlockchainJob $blockchainJob,
        Throwable $exception,
        BlockchainRetryService $retryService,
        int $attemptNumber,
    ): void {
        $sanitizedError = $retryService->sanitizeError($exception->getMessage());

        $record->markAsFailed($sanitizedError);

        $blockchainJob->update([
            'status' => 'failed',
            'finished_at' => now(),
            'last_error' => $sanitizedError,
        ]);

        if (! $retryService->canRetry($attemptNumber)) {
            $blockchainJob->update(['next_attempt_at' => null]);

            return;
        }

        $nextAttemptAt = $retryService->nextAttemptAt($attemptNumber);
        $nextAttemptNumber = $attemptNumber + 1;

        $blockchainJob->update([
            'next_attempt_at' => $nextAttemptAt,
        ]);

        $record->update([
            'status' => 'queued',
        ]);

        $queuedRetryJob = BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => $nextAttemptNumber,
            'max_attempts' => $retryService->maxAttempts(),
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => $sanitizedError,
        ]);

        self::dispatch(
            $record->id,
            isRetryAttempt: true,
            expectedBlockchainJobId: $queuedRetryJob->id,
        )->delay($nextAttemptAt);
    }
}

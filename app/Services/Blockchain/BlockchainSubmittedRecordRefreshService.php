<?php

namespace App\Services\Blockchain;

use App\Jobs\RefreshSubmittedBlockchainRecordJob;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class BlockchainSubmittedRecordRefreshService
{
    public const MSG_RECEIPT_PENDING = 'Transaction receipt is not yet available.';

    public const MSG_INSUFFICIENT_CONFIRMATIONS = 'Submitted transaction has not reached required confirmations.';

    public const MSG_TX_NOT_FOUND = 'Submitted transaction was not found by the configured RPC endpoint.';

    public const MSG_RECEIPT_FAILED = 'Ethereum transaction receipt indicates failure.';

    public function __construct(
        private readonly EthereumRpcClient $ethereumRpcClient,
        private readonly BlockchainRetryService $retryService,
    ) {}

    public function isEligibleForRefresh(
        BlockchainRecord $record,
        bool $matchCurrentConfig = false,
        bool $submittedStatusOnly = true,
    ): bool {
        if ($record->isConfirmed()) {
            return false;
        }

        if (! is_string($record->tx_hash) || trim($record->tx_hash) === '') {
            return false;
        }

        if ($record->confirmed_at !== null) {
            return false;
        }

        if ($submittedStatusOnly) {
            if ($record->status !== 'submitted') {
                return false;
            }
        } elseif (! in_array($record->status, ['queued', 'submitted', 'processing', 'failed'], true)) {
            return false;
        }

        if (! $matchCurrentConfig) {
            return true;
        }

        $configuredNetwork = config('blockchain.network');
        if (is_string($configuredNetwork) && $configuredNetwork !== '' && $record->network !== $configuredNetwork) {
            return false;
        }

        $configuredEnvironment = config('blockchain.environment');
        if (is_string($configuredEnvironment) && $configuredEnvironment !== '' && $record->environment !== $configuredEnvironment) {
            return false;
        }

        $configuredChainId = config('blockchain.chain_id');
        if ($configuredChainId !== null && $configuredChainId !== '' && (int) $record->chain_id !== (int) $configuredChainId) {
            return false;
        }

        $configuredContract = config('blockchain.contract_address');
        if (is_string($configuredContract) && trim($configuredContract) !== '' && is_string($record->contract_address)) {
            if (strtolower(trim($record->contract_address)) !== strtolower(trim($configuredContract))) {
                return false;
            }
        }

        return true;
    }

    public function eligibleRecordsQuery(
        ?string $network = null,
        ?string $environment = null,
        ?string $recordId = null,
    ): Builder {
        $query = BlockchainRecord::query()
            ->submitted()
            ->whereNotNull('tx_hash')
            ->whereNull('confirmed_at');

        if (is_string($network) && $network !== '') {
            $query->byNetwork($network);
        }

        if (is_string($environment) && $environment !== '') {
            $query->byEnvironment($environment);
        }

        if (is_string($recordId) && $recordId !== '') {
            $query->where('id', $recordId);
        }

        return $query;
    }

    public function refreshSubmittedRecord(
        BlockchainRecord $record,
        ?string $expectedBlockchainJobId = null,
        bool $submittedStatusOnly = true,
    ): void {
        $record->refresh();

        if (! $this->isEligibleForRefresh($record, submittedStatusOnly: $submittedStatusOnly)) {
            if ($expectedBlockchainJobId !== null) {
                $this->cancelExpectedRefreshJob($expectedBlockchainJobId, $record, 'Record is not eligible for submitted refresh.');
            }

            return;
        }

        if ($expectedBlockchainJobId !== null) {
            $blockchainJob = BlockchainJob::query()->find($expectedBlockchainJobId);

            if ($blockchainJob === null
                || $blockchainJob->blockchain_record_id !== $record->id
                || $blockchainJob->job_type !== 'refresh_confirmation'
                || $blockchainJob->status !== 'queued') {
                return;
            }

            $blockchainJob->update([
                'status' => 'processing',
                'max_attempts' => $this->retryService->maxAttempts(),
                'started_at' => now(),
                'finished_at' => null,
                'last_error' => null,
                'next_attempt_at' => null,
            ]);

            $attemptNumber = max(1, (int) $blockchainJob->attempts);
        } else {
            $attemptNumber = $this->nextRefreshAttemptNumber($record);
            $blockchainJob = $this->createRefreshJobRow($record, $attemptNumber, 'processing');
        }

        try {
            $txHash = strtolower(trim((string) $record->tx_hash));
            $receipt = $this->ethereumRpcClient->transactionReceipt($txHash);

            if ($receipt !== null) {
                $this->processReceipt($record, $blockchainJob, $attemptNumber, $receipt, $txHash);

                return;
            }

            $transaction = $this->ethereumRpcClient->transactionByHash($txHash);

            if ($transaction !== null) {
                $this->processPendingReceipt($record, $blockchainJob, $attemptNumber);

                return;
            }

            $this->processMissingTransaction($record, $blockchainJob, $attemptNumber);
        } catch (Throwable $exception) {
            $this->handleRefreshFailure($record, $blockchainJob, $attemptNumber, $exception);
        }
    }

    public function scheduleRefresh(BlockchainRecord $record, int $attemptNumber = 1): void
    {
        $record->refresh();

        if (! $this->isEligibleForRefresh($record)) {
            return;
        }

        $this->retryService->cancelQueuedRefreshJobs($record->id);

        $nextAttemptAt = $this->retryService->nextAttemptAt($attemptNumber);

        $queuedJob = BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => 'refresh_confirmation',
            'status' => 'queued',
            'attempts' => max(1, $attemptNumber),
            'max_attempts' => $this->retryService->maxAttempts(),
            'next_attempt_at' => $nextAttemptAt,
        ]);

        RefreshSubmittedBlockchainRecordJob::dispatch(
            $record->id,
            expectedBlockchainJobId: $queuedJob->id,
        )->delay($nextAttemptAt);
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function processReceipt(
        BlockchainRecord $record,
        BlockchainJob $blockchainJob,
        int $attemptNumber,
        array $receipt,
        string $txHash,
    ): void {
        if (! $this->ethereumRpcClient->receiptIndicatesSuccess($receipt)) {
            $sanitizedError = $this->retryService->sanitizeError(self::MSG_RECEIPT_FAILED);
            $record->markAsFailed($sanitizedError);
            $this->finishRefreshJob($blockchainJob, succeeded: false, error: $sanitizedError);

            return;
        }

        $blockNumber = $this->ethereumRpcClient->hexQuantityToInt((string) $receipt['blockNumber']);
        $confirmations = $this->ethereumRpcClient->confirmationsForReceipt($receipt);
        $requiredConfirmations = $this->ethereumRpcClient->requiredConfirmationBlocks();

        if ($confirmations >= $requiredConfirmations) {
            $record->markAsConfirmed($txHash, $blockNumber, $confirmations);
            $this->finishRefreshJob($blockchainJob, succeeded: true);

            return;
        }

        $sanitizedError = $this->retryService->sanitizeError(self::MSG_INSUFFICIENT_CONFIRMATIONS);

        $record->update([
            'tx_hash' => $txHash,
            'block_number' => $blockNumber,
            'confirmations' => $confirmations,
            'status' => 'submitted',
            'submitted_at' => $record->submitted_at ?? now(),
            'last_error' => $sanitizedError,
        ]);

        $this->finishRefreshJob($blockchainJob, succeeded: true);
        $this->scheduleRefresh($record->fresh(), $attemptNumber + 1);
    }

    private function processPendingReceipt(
        BlockchainRecord $record,
        BlockchainJob $blockchainJob,
        int $attemptNumber,
    ): void {
        $sanitizedError = $this->retryService->sanitizeError(self::MSG_RECEIPT_PENDING);

        $record->update([
            'status' => 'submitted',
            'last_error' => $sanitizedError,
        ]);

        $this->finishRefreshJob($blockchainJob, succeeded: true);
        $this->scheduleRefresh($record->fresh(), $attemptNumber + 1);
    }

    private function processMissingTransaction(
        BlockchainRecord $record,
        BlockchainJob $blockchainJob,
        int $attemptNumber,
    ): void {
        $sanitizedError = $this->retryService->sanitizeError(self::MSG_TX_NOT_FOUND);

        if (! $this->retryService->canRetry($attemptNumber)) {
            $record->markAsFailed($sanitizedError);
            $this->finishRefreshJob($blockchainJob, succeeded: false, error: $sanitizedError);

            return;
        }

        $record->update([
            'status' => 'submitted',
            'last_error' => $sanitizedError,
        ]);

        $nextAttemptAt = $this->retryService->nextAttemptAt($attemptNumber);

        $this->finishRefreshJob(
            $blockchainJob,
            succeeded: false,
            error: $sanitizedError,
            nextAttemptAt: $nextAttemptAt,
        );

        $this->scheduleRefresh($record->fresh(), $attemptNumber + 1);
    }

    private function handleRefreshFailure(
        BlockchainRecord $record,
        BlockchainJob $blockchainJob,
        int $attemptNumber,
        Throwable $exception,
    ): void {
        $sanitizedError = $this->retryService->sanitizeError($exception->getMessage());

        if (! $this->retryService->canRetry($attemptNumber)) {
            $record->markAsFailed($sanitizedError);
            $this->finishRefreshJob($blockchainJob, succeeded: false, error: $sanitizedError);

            return;
        }

        $record->update([
            'status' => 'submitted',
            'last_error' => $sanitizedError,
        ]);

        $nextAttemptAt = $this->retryService->nextAttemptAt($attemptNumber);

        $this->finishRefreshJob(
            $blockchainJob,
            succeeded: false,
            error: $sanitizedError,
            nextAttemptAt: $nextAttemptAt,
        );

        $this->scheduleRefresh($record->fresh(), $attemptNumber + 1);
    }

    private function finishRefreshJob(
        BlockchainJob $blockchainJob,
        bool $succeeded,
        ?string $error = null,
        ?\Carbon\CarbonInterface $nextAttemptAt = null,
    ): void {
        $blockchainJob->update([
            'status' => $succeeded ? 'success' : 'failed',
            'finished_at' => now(),
            'last_error' => $error,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }

    private function createRefreshJobRow(
        BlockchainRecord $record,
        int $attemptNumber,
        string $status,
    ): BlockchainJob {
        return BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => 'refresh_confirmation',
            'status' => $status,
            'attempts' => $attemptNumber,
            'max_attempts' => $this->retryService->maxAttempts(),
            'started_at' => $status === 'processing' ? now() : null,
        ]);
    }

    private function nextRefreshAttemptNumber(BlockchainRecord $record): int
    {
        $latestAttempt = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'refresh_confirmation')
            ->max('attempts');

        return max(1, (int) $latestAttempt + 1);
    }

    private function cancelExpectedRefreshJob(
        string $blockchainJobId,
        BlockchainRecord $record,
        string $reason,
    ): void {
        $job = BlockchainJob::query()->find($blockchainJobId);

        if ($job === null || $job->blockchain_record_id !== $record->id || $job->job_type !== 'refresh_confirmation') {
            return;
        }

        if ($job->status !== 'queued') {
            return;
        }

        $this->retryService->markRetryJobCancelled($job, $reason);
    }
}

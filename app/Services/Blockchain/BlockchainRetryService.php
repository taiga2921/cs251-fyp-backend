<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BlockchainRetryService
{
    public const STALE_RETRY_REASON = 'Skipped stale retry job; retry state has changed.';

    public const ACTIVE_RECORD_RETRY_REASON = 'Skipped stale retry job; record is already active.';

    public function maxAttempts(): int
    {
        $configured = config('blockchain.max_retries', 5);

        if (! is_int($configured) && ! is_string($configured)) {
            return 5;
        }

        $normalized = (int) $configured;

        return max(1, $normalized);
    }

    public function retryBaseSeconds(): int
    {
        $configured = config('blockchain.retry_base_seconds', 10);

        if (! is_int($configured) && ! is_string($configured)) {
            return 10;
        }

        $normalized = (int) $configured;

        return max(1, $normalized);
    }

    public function calculateDelaySeconds(int $attemptNumber): int
    {
        $retryBaseSeconds = $this->retryBaseSeconds();
        $normalizedAttempt = max(1, $attemptNumber);

        return $retryBaseSeconds * (2 ** max(0, $normalizedAttempt - 1));
    }

    public function nextAttemptAt(int $attemptNumber, ?CarbonInterface $from = null): CarbonInterface
    {
        $base = $from ?? now();

        return $base->copy()->addSeconds($this->calculateDelaySeconds($attemptNumber));
    }

    public function canRetry(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts();
    }

    public function sanitizeError(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/', '[rpc-url-redacted]', $message) ?? $message;
        $message = preg_replace('/0x[a-fA-F0-9]{64}/', '[secret-redacted]', $message) ?? $message;
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', '[token-redacted]', $message) ?? $message;

        $privateKey = config('blockchain.private_key');
        if (is_string($privateKey) && $privateKey !== '') {
            $message = str_replace($privateKey, '[secret-redacted]', $message);
        }

        return mb_substr(trim($message), 0, 1000);
    }

    public function staleRetryReason(BlockchainRecord $record, ?BlockchainJob $job): ?string
    {
        if ($job === null) {
            return self::STALE_RETRY_REASON;
        }

        if ($job->blockchain_record_id !== $record->id) {
            return self::STALE_RETRY_REASON;
        }

        if ($job->job_type !== 'retry_anchor') {
            return self::STALE_RETRY_REASON;
        }

        if ($job->status !== 'queued') {
            return self::STALE_RETRY_REASON;
        }

        if ($record->isProcessing() || $record->isSubmitted() || $record->isConfirmed()) {
            return self::ACTIVE_RECORD_RETRY_REASON;
        }

        $hasNewerQueuedRetry = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued')
            ->where('created_at', '>', $job->created_at)
            ->exists();

        if ($hasNewerQueuedRetry) {
            return self::STALE_RETRY_REASON;
        }

        $expectedAttempt = max(1, (int) $record->retry_count + 1);

        if ((int) $job->attempts !== $expectedAttempt) {
            return self::STALE_RETRY_REASON;
        }

        return null;
    }

    public function markRetryJobCancelled(BlockchainJob $job, string $reason): void
    {
        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'last_error' => $this->sanitizeError($reason),
            'next_attempt_at' => null,
        ]);
    }

    public function markExpectedRetryJobCancelledForRecord(
        string $blockchainJobId,
        BlockchainRecord $record,
        string $reason,
    ): void {
        $job = BlockchainJob::query()->find($blockchainJobId);

        if ($job === null) {
            return;
        }

        if ($job->blockchain_record_id !== $record->id) {
            return;
        }

        if ($job->job_type !== 'retry_anchor') {
            return;
        }

        if ($job->status !== 'queued') {
            return;
        }

        $this->markRetryJobCancelled($job, $reason);
    }

    public function cancelQueuedRetryJobs(string $blockchainRecordId, ?string $exceptJobId = null): int
    {
        $query = BlockchainJob::query()
            ->where('blockchain_record_id', $blockchainRecordId)
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued');

        if ($exceptJobId !== null) {
            $query->where('id', '!=', $exceptJobId);
        }

        return $query->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'last_error' => $this->sanitizeError(self::STALE_RETRY_REASON),
            'next_attempt_at' => null,
        ]);
    }

    public function cancelQueuedRefreshJobs(string $blockchainRecordId, ?string $exceptJobId = null): int
    {
        $query = BlockchainJob::query()
            ->where('blockchain_record_id', $blockchainRecordId)
            ->where('job_type', 'refresh_confirmation')
            ->where('status', 'queued');

        if ($exceptJobId !== null) {
            $query->where('id', '!=', $exceptJobId);
        }

        return $query->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'last_error' => $this->sanitizeError(self::STALE_RETRY_REASON),
            'next_attempt_at' => null,
        ]);
    }
}

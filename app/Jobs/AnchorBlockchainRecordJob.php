<?php

namespace App\Jobs;

use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
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

    public function __construct(
        public readonly string $blockchainRecordId,
    ) {}

    public function handle(EthereumRpcClient $ethereumRpcClient): void
    {
        $record = BlockchainRecord::query()->find($this->blockchainRecordId);

        if ($record === null) {
            return;
        }

        if ($record->isConfirmed()) {
            return;
        }

        $record->markAsProcessing();

        $blockchainJob = $this->createOrUpdateAnchorJob($record);

        try {
            $txHash = $ethereumRpcClient->storeHash(
                $record->record_hash,
                $record->contract_address
            );

            $record->markAsSubmitted($txHash);

            $receipt = $ethereumRpcClient->transactionReceipt($txHash);

            if ($receipt === null) {
                throw new RuntimeException('Transaction receipt is not yet available.');
            }

            if (! $this->receiptIndicatesSuccess($receipt)) {
                throw new RuntimeException('Ethereum transaction receipt indicates failure.');
            }

            $blockNumber = $ethereumRpcClient->hexQuantityToInt((string) $receipt['blockNumber']);
            $confirmations = $ethereumRpcClient->confirmationsForReceipt($receipt);
            $requiredConfirmations = max(1, (int) config('blockchain.confirmation_blocks', 1));

            if ($confirmations >= $requiredConfirmations) {
                $record->markAsConfirmed($txHash, $blockNumber, $confirmations);
            } else {
                $record->update([
                    'tx_hash' => $txHash,
                    'block_number' => $blockNumber,
                    'confirmations' => $confirmations,
                    'status' => 'submitted',
                    'submitted_at' => $record->submitted_at ?? now(),
                    'last_error' => null,
                ]);
            }

            $blockchainJob->update([
                'status' => 'success',
                'finished_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $sanitizedError = $this->sanitizeErrorMessage($exception->getMessage());

            $record->markAsFailed($sanitizedError);

            $blockchainJob->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $sanitizedError,
            ]);
        }
    }

    private function createOrUpdateAnchorJob(BlockchainRecord $record): BlockchainJob
    {
        $blockchainJob = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'anchor')
            ->whereIn('status', ['queued', 'processing'])
            ->latest('created_at')
            ->first();

        $maxAttempts = max(0, (int) config('blockchain.max_retries', 5));

        if ($blockchainJob === null) {
            return BlockchainJob::query()->create([
                'blockchain_record_id' => $record->id,
                'job_type' => 'anchor',
                'status' => 'processing',
                'attempts' => 1,
                'max_attempts' => $maxAttempts,
                'started_at' => now(),
            ]);
        }

        $blockchainJob->update([
            'status' => 'processing',
            'attempts' => $blockchainJob->attempts + 1,
            'max_attempts' => $maxAttempts,
            'started_at' => now(),
            'finished_at' => null,
            'last_error' => null,
        ]);

        return $blockchainJob->refresh();
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function receiptIndicatesSuccess(array $receipt): bool
    {
        $status = $receipt['status'] ?? null;

        if ($status === null) {
            return true;
        }

        if (is_int($status)) {
            return $status === 1;
        }

        if (! is_string($status)) {
            return false;
        }

        return in_array(strtolower($status), ['0x1', '0x01', '1'], true);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/', '[rpc-url-redacted]', $message) ?? $message;
        $message = preg_replace('/0x[a-fA-F0-9]{64}/', '[secret-redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1000);
    }
}

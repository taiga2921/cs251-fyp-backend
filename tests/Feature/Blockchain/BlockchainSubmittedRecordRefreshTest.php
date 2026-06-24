<?php

namespace Tests\Feature\Blockchain;

use App\Jobs\AnchorBlockchainRecordJob;
use App\Jobs\RefreshSubmittedBlockchainRecordJob;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainSubmittedRecordRefreshService;
use App\Services\Blockchain\EthereumRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BlockchainSubmittedRecordRefreshTest extends TestCase
{
    use RefreshDatabase;

    private string $txHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->txHash = '0x'.str_repeat('4', 64);

        config([
            'blockchain.enabled' => true,
            'blockchain.mode' => 'local',
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.private_key' => null,
            'blockchain.confirmation_blocks' => 1,
            'blockchain.max_retries' => 5,
            'blockchain.retry_base_seconds' => 10,
        ]);
    }

    public function test_submitted_record_confirms_on_refresh_without_resubmitting_transaction(): void
    {
        config(['blockchain.confirmation_blocks' => 1]);

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: true,
            includeTransaction: true,
        );

        $record = $this->createSubmittedRecord(blockNumber: 100, confirmations: 1);

        $this->runRefresh($record);

        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertNotNull($record->confirmed_at);
        $this->assertSame(100, $record->block_number);
        $this->assertSame(1, $record->confirmations);
        $this->assertNull($record->last_error);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
    }

    public function test_submitted_record_remains_submitted_when_confirmations_are_insufficient(): void
    {
        Queue::fake();
        config(['blockchain.confirmation_blocks' => 2]);

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: true,
            includeTransaction: true,
        );

        $record = $this->createSubmittedRecord(blockNumber: 100, confirmations: 1);

        $this->runRefresh($record);

        $record->refresh();

        $this->assertSame('submitted', $record->status);
        $this->assertNull($record->confirmed_at);
        $this->assertSame(1, $record->confirmations);
        $this->assertStringContainsString(
            'required confirmations',
            strtolower((string) $record->last_error)
        );

        Queue::assertPushed(RefreshSubmittedBlockchainRecordJob::class);
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
    }

    public function test_submitted_record_waits_when_receipt_missing_but_transaction_exists(): void
    {
        Queue::fake();

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: false,
            includeTransaction: true,
        );

        $record = $this->createSubmittedRecord();

        $this->runRefresh($record);

        $record->refresh();

        $this->assertSame('submitted', $record->status);
        $this->assertStringContainsString(
            'receipt is not yet available',
            strtolower((string) $record->last_error)
        );

        Queue::assertPushed(RefreshSubmittedBlockchainRecordJob::class);
    }

    public function test_missing_transaction_eventually_fails_after_retry_limit(): void
    {
        Queue::fake();
        config(['blockchain.max_retries' => 2]);

        $this->fakeRefreshRpc(
            includeReceipt: false,
            includeTransaction: false,
        );

        $record = $this->createSubmittedRecord();

        BlockchainJob::query()->create([
            'blockchain_record_id' => $record->id,
            'job_type' => 'refresh_confirmation',
            'status' => 'queued',
            'attempts' => 2,
            'max_attempts' => 2,
            'next_attempt_at' => now(),
        ]);

        $this->runRefresh($record, attemptNumber: 2);

        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString(
            'not found by the configured rpc endpoint',
            strtolower((string) $record->last_error)
        );
        $this->assertStringNotContainsString('http://', (string) $record->last_error);
    }

    public function test_confirmed_records_are_ignored_by_refresh(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'tx_hash' => $this->txHash,
            'record_hash' => str_repeat('a', 64),
        ]);

        $this->runRefresh($record);

        Http::assertNothingSent();
        $this->assertSame('confirmed', $record->fresh()->status);
        $this->assertSame(0, BlockchainJob::query()->where('job_type', 'refresh_confirmation')->count());
    }

    public function test_refresh_command_dispatches_only_eligible_submitted_records(): void
    {
        Queue::fake();

        $eligible = $this->createSubmittedRecord(network: 'sepolia', environment: 'staging');
        BlockchainRecord::factory()->confirmed()->create([
            'network' => 'sepolia',
            'environment' => 'staging',
            'tx_hash' => '0x'.str_repeat('5', 64),
        ]);
        BlockchainRecord::factory()->submitted()->create([
            'network' => 'ganache',
            'environment' => 'local',
            'tx_hash' => '0x'.str_repeat('6', 64),
        ]);

        Artisan::call('blockchain:refresh-submitted', [
            '--network' => 'sepolia',
            '--environment' => 'staging',
            '--limit' => 50,
        ]);

        Queue::assertPushed(RefreshSubmittedBlockchainRecordJob::class, 1);
        Queue::assertPushed(
            RefreshSubmittedBlockchainRecordJob::class,
            fn (RefreshSubmittedBlockchainRecordJob $job): bool => $job->blockchainRecordId === $eligible->id
        );
    }

    public function test_anchor_job_schedules_refresh_when_confirmations_are_insufficient(): void
    {
        Queue::fake();
        config(['blockchain.confirmation_blocks' => 2]);

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: true,
            includeTransaction: true,
            includeSendTransaction: true,
        );

        $record = BlockchainRecord::factory()->pending()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 1337,
        ]);

        (new AnchorBlockchainRecordJob($record->id))
            ->handle(
                app(EthereumRpcClient::class),
                app(\App\Services\Blockchain\BlockchainRetryService::class),
                app(BlockchainSubmittedRecordRefreshService::class),
            );

        $record->refresh();

        $this->assertSame('submitted', $record->status);
        $this->assertSame($this->txHash, $record->tx_hash);
        $this->assertNull($record->confirmed_at);

        Queue::assertPushed(RefreshSubmittedBlockchainRecordJob::class);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
    }

    public function test_submitted_record_with_existing_tx_hash_does_not_resubmit_on_anchor_job(): void
    {
        $record = $this->createSubmittedRecord();

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: true,
            includeTransaction: true,
        );

        (new AnchorBlockchainRecordJob($record->id))
            ->handle(
                app(EthereumRpcClient::class),
                app(\App\Services\Blockchain\BlockchainRetryService::class),
                app(BlockchainSubmittedRecordRefreshService::class),
            );

        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
    }

    public function test_queued_record_with_existing_tx_hash_refreshes_without_resubmitting(): void
    {
        config(['blockchain.confirmation_blocks' => 1]);

        $this->fakeRefreshRpc(
            receiptBlockNumber: 100,
            latestBlockNumber: 100,
            includeReceipt: true,
            includeTransaction: true,
        );

        $record = BlockchainRecord::factory()->queued()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 1337,
            'network' => 'ganache',
            'environment' => 'local',
            'tx_hash' => $this->txHash,
            'submitted_at' => now(),
            'block_number' => null,
            'confirmations' => 0,
        ]);

        (new AnchorBlockchainRecordJob($record->id))
            ->handle(
                app(EthereumRpcClient::class),
                app(\App\Services\Blockchain\BlockchainRetryService::class),
                app(BlockchainSubmittedRecordRefreshService::class),
            );

        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertNotNull($record->confirmed_at);
        $this->assertSame(100, $record->block_number);
        $this->assertSame(1, $record->confirmations);
        $this->assertNull($record->last_error);

        Http::assertSent(fn ($request) => str_contains($request->body(), 'eth_getTransactionReceipt'));
        Http::assertSent(fn ($request) => str_contains($request->body(), 'eth_blockNumber'));
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendRawTransaction'));
    }

    public function test_anchor_job_keeps_record_submitted_and_schedules_refresh_when_receipt_is_missing_after_store_hash(): void
    {
        Queue::fake();

        config([
            'blockchain.network' => 'sepolia',
            'blockchain.mode' => 'testnet',
            'blockchain.environment' => 'staging',
            'blockchain.chain_id' => 11155111,
            'blockchain.private_key' => '0x'.str_repeat('c', 64),
        ]);

        $this->fakeSepoliaAnchorRpcWithMissingReceipt();

        $record = BlockchainRecord::factory()->pending()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 11155111,
            'network' => 'sepolia',
            'environment' => 'staging',
            'tx_hash' => null,
            'retry_count' => 0,
        ]);

        (new AnchorBlockchainRecordJob($record->id))
            ->handle(
                app(EthereumRpcClient::class),
                app(\App\Services\Blockchain\BlockchainRetryService::class),
                app(BlockchainSubmittedRecordRefreshService::class),
            );

        $record->refresh();

        $this->assertSame('submitted', $record->status);
        $this->assertSame($this->txHash, $record->tx_hash);
        $this->assertNotNull($record->submitted_at);
        $this->assertNull($record->confirmed_at);
        $this->assertNull($record->block_number);
        $this->assertSame(0, $record->confirmations);
        $this->assertSame(0, $record->retry_count);
        $this->assertStringContainsString(
            'receipt is not yet available',
            strtolower((string) $record->last_error)
        );
        $this->assertStringNotContainsString(
            (string) config('blockchain.private_key'),
            (string) $record->last_error
        );

        $this->assertDatabaseHas('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'anchor',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'refresh_confirmation',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'retry_anchor',
        ]);

        $refreshJob = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'refresh_confirmation')
            ->first();
        $this->assertNotNull($refreshJob);
        $this->assertNotNull($refreshJob->next_attempt_at);

        Queue::assertPushed(
            RefreshSubmittedBlockchainRecordJob::class,
            fn (RefreshSubmittedBlockchainRecordJob $job): bool => $job->blockchainRecordId === $record->id
        );

        $sendRawCount = 0;
        $receiptCallCount = 0;

        Http::assertSent(function ($request) use (&$sendRawCount, &$receiptCallCount): bool {
            $body = json_decode($request->body(), true);
            $method = $body['method'] ?? null;

            if ($method === 'eth_sendRawTransaction') {
                $sendRawCount++;
            }

            if ($method === 'eth_getTransactionReceipt') {
                $receiptCallCount++;
            }

            return true;
        });

        $this->assertSame(1, $sendRawCount);
        $this->assertSame(1, $receiptCallCount);
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'eth_sendTransaction'));
    }

    private function createSubmittedRecord(
        ?int $blockNumber = null,
        int $confirmations = 0,
        string $network = 'ganache',
        string $environment = 'local',
    ): BlockchainRecord {
        return BlockchainRecord::factory()->submitted()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 1337,
            'network' => $network,
            'environment' => $environment,
            'tx_hash' => $this->txHash,
            'block_number' => $blockNumber,
            'confirmations' => $confirmations,
        ]);
    }

    private function runRefresh(BlockchainRecord $record, int $attemptNumber = 1): void
    {
        app(BlockchainSubmittedRecordRefreshService::class)
            ->refreshSubmittedRecord($record->fresh());
    }

    private function fakeRefreshRpc(
        int $receiptBlockNumber = 100,
        int $latestBlockNumber = 100,
        bool $includeReceipt = true,
        bool $includeTransaction = true,
        bool $includeSendTransaction = false,
    ): void {
        Http::fake(function ($request) use (
            $receiptBlockNumber,
            $latestBlockNumber,
            $includeReceipt,
            $includeTransaction,
            $includeSendTransaction,
        ) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_sendTransaction' => $includeSendTransaction
                    ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->txHash])
                    : Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'error' => ['code' => -32601, 'message' => 'Unexpected send in refresh test.'],
                    ], 500),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $includeReceipt ? [
                        'transactionHash' => $this->txHash,
                        'blockNumber' => '0x'.dechex($receiptBlockNumber),
                        'status' => '0x1',
                    ] : null,
                ]),
                'eth_getTransactionByHash' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $includeTransaction ? [
                        'hash' => $this->txHash,
                        'blockNumber' => '0x'.dechex($receiptBlockNumber),
                    ] : null,
                ]),
                'eth_blockNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x'.dechex($latestBlockNumber),
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test: '.($body['method'] ?? 'unknown')],
                ], 500),
            };
        });
    }

    private function fakeSepoliaAnchorRpcWithMissingReceipt(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xaa36a7']),
                'eth_getTransactionCount' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']),
                'eth_gasPrice' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3b9aca00']),
                'eth_estimateGas' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x5208']),
                'eth_sendRawTransaction' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->txHash]),
                'eth_getTransactionReceipt' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test: '.($body['method'] ?? 'unknown')],
                ], 500),
            };
        });
    }
}

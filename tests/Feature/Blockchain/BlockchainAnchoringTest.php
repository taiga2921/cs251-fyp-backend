<?php

namespace Tests\Feature\Blockchain;

use App\Jobs\AnchorBlockchainRecordJob;
use App\Models\AnprEvent;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainRecordService;
use App\Services\Blockchain\EthereumRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlockchainAnchoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.enabled' => true,
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.confirmation_blocks' => 1,
            'blockchain.max_retries' => 5,
        ]);
    }

    public function test_pending_record_can_be_anchored_and_becomes_confirmed(): void
    {
        $txHash = '0x'.str_repeat('3', 64);
        $this->fakeSuccessfulAnchoringRpc($txHash, 100);

        $record = $this->createPendingRecord();

        $this->runAnchorJob($record);

        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertSame($txHash, $record->tx_hash);
        $this->assertSame(100, $record->block_number);
        $this->assertSame(1, $record->confirmations);
        $this->assertNotNull($record->submitted_at);
        $this->assertNotNull($record->confirmed_at);
        $this->assertNull($record->last_error);
    }

    public function test_anchor_job_audit_row_becomes_success(): void
    {
        $this->fakeSuccessfulAnchoringRpc();

        $record = $this->createPendingRecord();
        $this->runAnchorJob($record);

        $job = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('job_type', 'anchor')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame('success', $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->last_error);
    }

    public function test_existing_confirmed_record_is_skipped_safely(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->confirmed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);

        $this->runAnchorJob($record);

        Http::assertNothingSent();
        $this->assertSame(0, BlockchainJob::query()->count());
    }

    public function test_rpc_failure_marks_record_and_job_as_failed(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Sender account not authorized',
                ],
            ]),
        ]);

        $record = $this->createPendingRecord();
        $this->runAnchorJob($record);

        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('Sender account not authorized', (string) $record->last_error);
        $this->assertStringNotContainsString('http://', (string) $record->last_error);

        $job = BlockchainJob::query()->where('blockchain_record_id', $record->id)->first();
        $this->assertNotNull($job);
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->finished_at);
    }

    public function test_record_service_dispatches_anchoring_only_when_blockchain_is_enabled(): void
    {
        Bus::fake();

        config(['blockchain.enabled' => true]);

        app(BlockchainRecordService::class)->createForEntity(AnprEvent::factory()->create());

        Bus::assertDispatched(AnchorBlockchainRecordJob::class);
    }

    public function test_record_service_does_not_dispatch_anchoring_when_blockchain_is_disabled(): void
    {
        Bus::fake();

        config(['blockchain.enabled' => false]);

        app(BlockchainRecordService::class)->createForEntity(AnprEvent::factory()->create());

        Bus::assertNotDispatched(AnchorBlockchainRecordJob::class);
    }

    private function createPendingRecord(): BlockchainRecord
    {
        return BlockchainRecord::factory()->pending()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 1337,
        ]);
    }

    private function runAnchorJob(BlockchainRecord $record): void
    {
        (new AnchorBlockchainRecordJob($record->id))
            ->handle(app(EthereumRpcClient::class));
    }

    private function fakeSuccessfulAnchoringRpc(
        string $txHash = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd',
        int $blockNumber = 100,
    ): void {
        Http::fake(function ($request) use ($txHash, $blockNumber) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_sendTransaction' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]),
                'eth_getTransactionReceipt' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'transactionHash' => $txHash,
                    'blockNumber' => '0x'.dechex($blockNumber),
                    'status' => '0x1',
                ]]),
                'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.dechex($blockNumber)]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });
    }
}

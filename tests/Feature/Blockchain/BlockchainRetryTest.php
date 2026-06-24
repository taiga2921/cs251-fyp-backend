<?php

namespace Tests\Feature\Blockchain;

use App\Jobs\AnchorBlockchainRecordJob;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\EthereumRpcClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class BlockchainRetryTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config([
            'blockchain.enabled' => true,
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.confirmation_blocks' => 1,
            'blockchain.max_retries' => 3,
            'blockchain.retry_base_seconds' => 10,
        ]);
    }

    public function test_temporary_rpc_failure_schedules_retry_with_next_attempt_at(): void
    {
        Queue::fake();

        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused',
                ],
            ]),
        ]);

        Carbon::setTestNow('2026-06-24 12:00:00');

        $record = $this->createPendingRecord();
        $this->runAnchorJob($record);

        $job = BlockchainJob::query()->where('blockchain_record_id', $record->id)->first();

        $this->assertNotNull($job);
        $this->assertSame('failed', $job->status);
        $this->assertSame('anchor', $job->job_type);
        $this->assertSame('2026-06-24 12:00:10', $job->next_attempt_at?->format('Y-m-d H:i:s'));

        Queue::assertPushed(AnchorBlockchainRecordJob::class, function (AnchorBlockchainRecordJob $job): bool {
            return $job->isRetryAttempt === true;
        });

        Carbon::setTestNow();
    }

    public function test_exponential_backoff_increases_delay_across_failures(): void
    {
        Queue::fake();

        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused',
                ],
            ]),
        ]);

        Carbon::setTestNow('2026-06-24 12:00:00');

        $record = $this->createPendingRecord();

        $this->runAnchorJob($record, isRetryAttempt: false);
        $firstJob = BlockchainJob::query()->where('job_type', 'anchor')->first();
        $this->assertSame('2026-06-24 12:00:10', $firstJob?->next_attempt_at?->format('Y-m-d H:i:s'));

        $record->refresh();
        $this->runAnchorJob($record, isRetryAttempt: true);
        $secondJob = BlockchainJob::query()->where('job_type', 'retry_anchor')->latest('created_at')->first();
        $this->assertSame('2026-06-24 12:00:20', $secondJob?->next_attempt_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_retry_stops_after_configured_max_attempts(): void
    {
        Queue::fake();

        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused',
                ],
            ]),
        ]);

        $record = $this->createPendingRecord();

        $this->runAnchorJob($record, isRetryAttempt: false);
        $record->refresh();
        $this->assertSame('queued', $record->status);

        $record->update(['status' => 'queued']);
        $this->runAnchorJob($record, isRetryAttempt: true);
        $record->refresh();
        $this->assertSame('queued', $record->status);

        $record->update(['status' => 'queued']);
        $this->runAnchorJob($record, isRetryAttempt: true);
        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertSame(3, $record->retry_count);

        $lastJob = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('attempts', 3)
            ->first();

        $this->assertNotNull($lastJob);
        $this->assertSame('failed', $lastJob->status);
        $this->assertNull($lastJob->next_attempt_at);

        Queue::assertPushed(AnchorBlockchainRecordJob::class, 2);
    }

    public function test_successful_retry_eventually_marks_record_confirmed(): void
    {
        Queue::fake();

        $txHash = '0x'.str_repeat('9', 64);
        $sendAttempts = 0;

        Http::fake(function ($request) use ($txHash, &$sendAttempts) {
            $body = json_decode($request->body(), true);
            $method = $body['method'] ?? null;

            if ($method === 'eth_sendTransaction') {
                $sendAttempts++;

                if ($sendAttempts === 1) {
                    return Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'error' => [
                            'code' => -32000,
                            'message' => 'Connection refused',
                        ],
                    ]);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]);
            }

            return match ($method) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_getTransactionReceipt' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'transactionHash' => $txHash,
                    'blockNumber' => '0x64',
                    'status' => '0x1',
                ]]),
                'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x64']),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $record = $this->createPendingRecord();
        $this->runAnchorJob($record);

        $record->refresh();
        $this->assertSame('queued', $record->status);

        $record->update(['status' => 'queued']);
        $this->runAnchorJob($record, isRetryAttempt: true);

        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertSame($txHash, $record->tx_hash);
        $this->assertNull($record->last_error);

        $successJob = BlockchainJob::query()
            ->where('blockchain_record_id', $record->id)
            ->where('status', 'success')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($successJob);
        $this->assertSame('retry_anchor', $successJob->job_type);
    }

    public function test_existing_tx_hash_is_confirmed_without_resubmitting_store_hash(): void
    {
        $txHash = '0x'.str_repeat('8', 64);

        Http::fake(function ($request) use ($txHash) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_getTransactionReceipt' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'transactionHash' => $txHash,
                    'blockNumber' => '0x64',
                    'status' => '0x1',
                ]]),
                'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x64']),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'tx_hash' => $txHash,
            'chain_id' => 1337,
        ]);

        $this->runAnchorJob($record, isRetryAttempt: true);

        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        Http::assertSentCount(2);
    }

    public function test_admin_can_retry_failed_record(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.retry_count', $record->retry_count)
            ->assertJsonPath('data.jobs.0.job_type', 'retry_anchor')
            ->assertJsonPath('data.jobs.0.status', 'queued');

        Queue::assertPushed(AnchorBlockchainRecordJob::class, function (AnchorBlockchainRecordJob $job) use ($record): bool {
            return $job->blockchainRecordId === $record->id && $job->isRetryAttempt === true;
        });
    }

    public function test_security_operator_cannot_retry_failed_record(): void
    {
        $operator = $this->securityOperatorUser();
        $record = BlockchainRecord::factory()->failed()->create();

        $this->actingAs($operator, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only administrators may perform this action.');
    }

    public function test_guard_cannot_retry_failed_record(): void
    {
        $guard = $this->guardUser();
        $record = BlockchainRecord::factory()->failed()->create();

        $this->actingAs($guard, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertForbidden();
    }

    public function test_confirmed_record_cannot_be_retried(): void
    {
        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->confirmed()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only failed blockchain records can be retried.');
    }

    public function test_non_failed_active_record_cannot_be_retried(): void
    {
        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->queued()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only failed blockchain records can be retried.');
    }

    public function test_show_response_includes_retry_and_job_fields(): void
    {
        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'retry_count' => 2,
            'last_error' => 'Connection refused',
        ]);

        BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'failed',
            'attempts' => 2,
            'max_attempts' => 5,
            'next_attempt_at' => now()->addSeconds(20),
            'last_error' => 'Connection refused',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.retry_count', 2)
            ->assertJsonPath('data.last_error', 'Connection refused')
            ->assertJsonPath('data.jobs.0.job_type', 'retry_anchor')
            ->assertJsonPath('data.jobs.0.status', 'failed')
            ->assertJsonPath('data.jobs.0.attempts', 2)
            ->assertJsonPath('data.jobs.0.max_attempts', 5)
            ->assertJsonPath('data.jobs.0.last_error', 'Connection refused');
    }

    private function createPendingRecord(): BlockchainRecord
    {
        return BlockchainRecord::factory()->pending()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'chain_id' => 1337,
        ]);
    }

    private function runAnchorJob(BlockchainRecord $record, bool $isRetryAttempt = false): void
    {
        (new AnchorBlockchainRecordJob($record->id, $isRetryAttempt))
            ->handle(app(EthereumRpcClient::class), app(\App\Services\Blockchain\BlockchainRetryService::class));
    }
}

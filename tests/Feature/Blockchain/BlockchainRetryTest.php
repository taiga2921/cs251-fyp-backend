<?php

namespace Tests\Feature\Blockchain;

use App\Jobs\AnchorBlockchainRecordJob;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainRetryService;
use App\Services\Blockchain\EthereumRpcClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'blockchain.mode' => 'local',
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.private_key' => null,
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
        $queuedRetryJob = BlockchainJob::query()
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued')
            ->first();
        $this->assertNotNull($queuedRetryJob);
        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedRetryJob->id);
        $secondJob = BlockchainJob::query()
            ->where('job_type', 'retry_anchor')
            ->where('status', 'failed')
            ->latest('created_at')
            ->first();
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
        $queuedRetryJob = BlockchainJob::query()
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued')
            ->where('attempts', 2)
            ->first();
        $this->assertNotNull($queuedRetryJob);
        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedRetryJob->id);
        $record->refresh();
        $this->assertSame('queued', $record->status);

        $record->update(['status' => 'queued']);
        $queuedRetryJob = BlockchainJob::query()
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued')
            ->where('attempts', 3)
            ->first();
        $this->assertNotNull($queuedRetryJob);
        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedRetryJob->id);
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

        $queuedRetryJob = BlockchainJob::query()
            ->where('job_type', 'retry_anchor')
            ->where('status', 'queued')
            ->first();
        $this->assertNotNull($queuedRetryJob);

        $record->update(['status' => 'queued']);
        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedRetryJob->id);

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

    public function test_stale_automatic_retry_job_is_skipped_without_rpc_or_retry_increment(): void
    {
        Http::fake();

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 2,
        ]);

        $staleJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 2,
            'max_attempts' => 3,
            'next_attempt_at' => now()->addSeconds(10),
            'created_at' => now()->subMinute(),
        ]);

        BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 3,
            'max_attempts' => 3,
            'next_attempt_at' => now()->addSeconds(20),
            'created_at' => now(),
        ]);

        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $staleJob->id);

        $staleJob->refresh();
        $record->refresh();

        Http::assertNothingSent();
        $this->assertSame(2, $record->retry_count);
        $this->assertSame('failed', $record->status);
        $this->assertSame('cancelled', $staleJob->status);
        $this->assertNotNull($staleJob->finished_at);
        $this->assertStringContainsString('Skipped stale retry job', (string) $staleJob->last_error);
        $this->assertSame(
            0,
            BlockchainJob::query()
                ->where('blockchain_record_id', $record->id)
                ->where('status', 'processing')
                ->count()
        );
    }

    #[DataProvider('activeRecordStatusProvider')]
    public function test_retry_job_is_cancelled_without_rpc_when_record_is_active(string $recordState): void
    {
        Http::fake(function (): never {
            $this->fail('Stale retry job should not call JSON-RPC.');
        });

        $record = match ($recordState) {
            'processing' => BlockchainRecord::factory()->create([
                'record_hash' => str_repeat('a', 64),
                'contract_address' => '0x'.str_repeat('a', 40),
                'status' => 'processing',
                'retry_count' => 2,
            ]),
            'submitted' => BlockchainRecord::factory()->submitted()->create([
                'record_hash' => str_repeat('a', 64),
                'contract_address' => '0x'.str_repeat('a', 40),
                'retry_count' => 2,
            ]),
            'confirmed' => BlockchainRecord::factory()->confirmed()->create([
                'record_hash' => str_repeat('a', 64),
                'contract_address' => '0x'.str_repeat('a', 40),
                'retry_count' => 2,
            ]),
            default => throw new \InvalidArgumentException("Unsupported record state: {$recordState}"),
        };

        $queuedJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 3,
            'max_attempts' => 5,
            'next_attempt_at' => now()->addSeconds(10),
        ]);

        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedJob->id);

        $queuedJob->refresh();
        $record->refresh();

        $this->assertSame('cancelled', $queuedJob->status);
        $this->assertNotNull($queuedJob->finished_at);
        $this->assertStringContainsString(
            'record is already active',
            (string) $queuedJob->last_error
        );
        $this->assertSame(2, $record->retry_count);
        $this->assertSame(
            0,
            BlockchainJob::query()
                ->where('blockchain_record_id', $record->id)
                ->where('status', 'processing')
                ->count()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function activeRecordStatusProvider(): array
    {
        return [
            'processing' => ['processing'],
            'submitted' => ['submitted'],
            'confirmed' => ['confirmed'],
        ];
    }

    public function test_confirmed_record_cancels_only_matching_queued_retry_job(): void
    {
        Http::fake(function (): never {
            $this->fail('Confirmed short-circuit should not call JSON-RPC.');
        });

        $record = BlockchainRecord::factory()->confirmed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 2,
        ]);

        $queuedJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 3,
            'max_attempts' => 5,
            'next_attempt_at' => now()->addSeconds(10),
        ]);

        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedJob->id);

        $queuedJob->refresh();
        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertSame('cancelled', $queuedJob->status);
        $this->assertNotNull($queuedJob->finished_at);
        $this->assertStringContainsString(
            'record is already active',
            (string) $queuedJob->last_error
        );
    }

    #[DataProvider('unrelatedExpectedJobProvider')]
    public function test_confirmed_record_does_not_cancel_unrelated_expected_job_id(string $scenario): void
    {
        Http::fake(function (): never {
            $this->fail('Confirmed short-circuit should not call JSON-RPC.');
        });

        $confirmedRecord = BlockchainRecord::factory()->confirmed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 2,
        ]);

        $otherRecord = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('b', 64),
            'contract_address' => '0x'.str_repeat('b', 40),
            'retry_count' => 1,
        ]);

        $expectedJob = match ($scenario) {
            'other_record' => BlockchainJob::factory()->for($otherRecord)->create([
                'job_type' => 'retry_anchor',
                'status' => 'queued',
                'attempts' => 2,
            ]),
            'wrong_job_type' => BlockchainJob::factory()->for($confirmedRecord)->create([
                'job_type' => 'anchor',
                'status' => 'queued',
                'attempts' => 3,
            ]),
            'wrong_status' => BlockchainJob::factory()->for($confirmedRecord)->create([
                'job_type' => 'retry_anchor',
                'status' => 'processing',
                'attempts' => 3,
                'started_at' => now(),
            ]),
            default => throw new \InvalidArgumentException("Unsupported scenario: {$scenario}"),
        };

        $originalStatus = $expectedJob->status;

        $this->runAnchorJob(
            $confirmedRecord,
            isRetryAttempt: true,
            expectedBlockchainJobId: $expectedJob->id,
        );

        $expectedJob->refresh();
        $confirmedRecord->refresh();

        $this->assertSame($originalStatus, $expectedJob->status);
        $this->assertNull($expectedJob->finished_at);
        $this->assertSame('confirmed', $confirmedRecord->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrelatedExpectedJobProvider(): array
    {
        return [
            'other_record' => ['other_record'],
            'wrong_job_type' => ['wrong_job_type'],
            'wrong_status' => ['wrong_status'],
        ];
    }

    public function test_stale_retry_does_not_cancel_unrelated_expected_job_id(): void
    {
        Http::fake(function (): never {
            $this->fail('Stale retry job should not call JSON-RPC.');
        });

        $recordA = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 2,
        ]);

        $recordB = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('b', 64),
            'contract_address' => '0x'.str_repeat('b', 40),
            'retry_count' => 1,
        ]);

        $otherRecordJob = BlockchainJob::factory()->for($recordB)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 2,
        ]);

        $this->runAnchorJob(
            $recordA,
            isRetryAttempt: true,
            expectedBlockchainJobId: $otherRecordJob->id,
        );

        $otherRecordJob->refresh();
        $recordA->refresh();

        $this->assertSame('queued', $otherRecordJob->status);
        $this->assertNull($otherRecordJob->finished_at);
        $this->assertSame(2, $recordA->retry_count);
    }

    #[DataProvider('invalidStaleExpectedJobProvider')]
    public function test_stale_retry_does_not_cancel_wrong_type_or_status_job(string $scenario): void
    {
        Http::fake(function (): never {
            $this->fail('Stale retry job should not call JSON-RPC.');
        });

        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 1,
        ]);

        $expectedJob = match ($scenario) {
            'wrong_job_type' => BlockchainJob::factory()->for($record)->create([
                'job_type' => 'anchor',
                'status' => 'queued',
                'attempts' => 2,
            ]),
            'wrong_status' => BlockchainJob::factory()->for($record)->create([
                'job_type' => 'retry_anchor',
                'status' => 'processing',
                'attempts' => 2,
                'started_at' => now(),
            ]),
            default => throw new \InvalidArgumentException("Unsupported scenario: {$scenario}"),
        };

        $originalStatus = $expectedJob->status;

        $this->runAnchorJob(
            $record,
            isRetryAttempt: true,
            expectedBlockchainJobId: $expectedJob->id,
        );

        $expectedJob->refresh();
        $record->refresh();

        $this->assertSame($originalStatus, $expectedJob->status);
        $this->assertNull($expectedJob->finished_at);
        $this->assertSame(1, $record->retry_count);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidStaleExpectedJobProvider(): array
    {
        return [
            'wrong_job_type' => ['wrong_job_type'],
            'wrong_status' => ['wrong_status'],
        ];
    }

    public function test_manual_retry_cancels_superseded_queued_retry_jobs(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('a', 40),
            'retry_count' => 1,
        ]);

        $supersededJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 2,
            'max_attempts' => 5,
            'next_attempt_at' => now()->addSeconds(30),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertOk();

        $supersededJob->refresh();

        $this->assertSame('cancelled', $supersededJob->status);
        $this->assertStringContainsString('Skipped stale retry job', (string) $supersededJob->last_error);

        $this->assertSame(
            1,
            BlockchainJob::query()
                ->where('blockchain_record_id', $record->id)
                ->where('job_type', 'retry_anchor')
                ->where('status', 'queued')
                ->count()
        );
    }

    public function test_current_delayed_retry_job_still_executes_normally(): void
    {
        $txHash = '0x'.str_repeat('7', 64);

        Http::fake(function ($request) use ($txHash) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_sendTransaction' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]),
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
            'chain_id' => 1337,
            'retry_count' => 1,
        ]);

        $queuedJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 2,
            'max_attempts' => 3,
            'next_attempt_at' => now()->addSeconds(10),
        ]);

        $record->update(['status' => 'queued']);

        $this->runAnchorJob($record, isRetryAttempt: true, expectedBlockchainJobId: $queuedJob->id);

        $queuedJob->refresh();
        $record->refresh();

        $this->assertSame('confirmed', $record->status);
        $this->assertSame('success', $queuedJob->status);
        $this->assertSame($txHash, $record->tx_hash);
        $this->assertNotNull($queuedJob->finished_at);
    }

    public function test_show_blockchain_record_returns_jobs_newest_first(): void
    {
        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create();

        $olderJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'anchor',
            'status' => 'failed',
            'created_at' => now()->subMinutes(5),
        ]);

        $newerJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.jobs.0.id', $newerJob->id)
            ->assertJsonPath('data.jobs.1.id', $olderJob->id);
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

    private function runAnchorJob(
        BlockchainRecord $record,
        bool $isRetryAttempt = false,
        ?string $expectedBlockchainJobId = null,
    ): void {
        (new AnchorBlockchainRecordJob($record->id, $isRetryAttempt, $expectedBlockchainJobId))
            ->handle(
                app(EthereumRpcClient::class),
                app(\App\Services\Blockchain\BlockchainRetryService::class),
                app(\App\Services\Blockchain\BlockchainSubmittedRecordRefreshService::class),
            );
    }
}

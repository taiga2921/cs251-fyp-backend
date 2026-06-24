<?php

namespace Tests\Feature\Blockchain;

use App\Models\AnprEvent;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Models\BlockchainVerification;
use App\Services\Blockchain\BlockchainHashService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class BlockchainVerificationTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config([
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);
    }

    public function test_admin_can_manually_verify_a_record(): void
    {
        $this->fakeVerifyRpc(found: true);

        $admin = $this->adminUser();
        $record = $this->createConfirmedAnprRecord();

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'valid');
    }

    public function test_security_operator_can_manually_verify_a_record(): void
    {
        $this->fakeVerifyRpc(found: true);

        $operator = $this->securityOperatorUser();
        $record = $this->createConfirmedAnprRecord();

        $this->actingAs($operator, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'valid');
    }

    public function test_guard_receives_403_when_verifying(): void
    {
        Http::fake();

        $guard = $this->guardUser();
        $record = $this->createConfirmedAnprRecord();

        $this->actingAs($guard, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_receives_401_when_verifying(): void
    {
        $record = $this->createConfirmedAnprRecord();

        $this->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertUnauthorized();
    }

    public function test_manual_verification_response_includes_result(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'pending-response'),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'pending');
    }

    public function test_manual_verification_persists_blockchain_verifications_row(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'persist-row'),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated();

        $this->assertDatabaseHas('blockchain_verifications', [
            'blockchain_record_id' => $record->id,
            'verification_type' => 'manual',
            'result' => 'pending',
        ]);
    }

    public function test_manual_verification_creates_verify_job_row(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'verify-job'),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated();

        $this->assertDatabaseHas('blockchain_jobs', [
            'blockchain_record_id' => $record->id,
            'job_type' => 'verify',
            'status' => 'success',
        ]);
    }

    public function test_tampered_source_entity_returns_tampered(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = $this->createConfirmedAnprRecord();
        AnprEvent::query()->findOrFail($record->entity_id)->update(['plate_number' => 'TAMPERED']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'tampered');

        Http::assertNothingSent();
    }

    public function test_unconfirmed_record_returns_pending_without_rpc(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->queued()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => AnprEvent::factory()->create()->id,
            'record_hash' => hash('sha256', 'queued-verify'),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'pending');

        Http::assertNothingSent();
    }

    public function test_onchain_false_returns_onchain_missing(): void
    {
        $this->fakeVerifyRpc(found: false);

        $admin = $this->adminUser();
        $record = $this->createConfirmedAnprRecord();

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'onchain_missing')
            ->assertJsonPath('data.onchain_found', false);
    }

    public function test_rpc_failure_returns_and_stores_failed_with_sanitized_error(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused at http://127.0.0.1:7545 Bearer secret.token',
                ],
            ]),
        ]);

        $admin = $this->adminUser();
        $record = $this->createConfirmedAnprRecord();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated()
            ->assertJsonPath('data.result', 'failed');

        $this->assertStringNotContainsString('http://127.0.0.1:7545', (string) $response->json('data.error_message'));
        $this->assertStringContainsString('[rpc-url-redacted]', (string) $response->json('data.error_message'));
    }

    public function test_show_includes_verification_history_after_verification(): void
    {
        Http::fake();

        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->failed()->create([
            'record_hash' => hash('sha256', 'show-history'),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/verify')
            ->assertCreated();

        $verification = BlockchainVerification::query()
            ->where('blockchain_record_id', $record->id)
            ->latest('verified_at')
            ->first();

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.verifications.0.id', $verification?->id)
            ->assertJsonPath('data.verifications.0.result', 'pending');
    }

    public function test_retry_endpoint_remains_admin_only(): void
    {
        Queue::fake();

        $operator = $this->securityOperatorUser();
        $record = BlockchainRecord::factory()->failed()->create();

        $this->actingAs($operator, 'api')
            ->postJson('/api/blockchain-records/'.$record->id.'/retry')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only administrators may perform this action.');
    }

    private function createConfirmedAnprRecord(): BlockchainRecord
    {
        $event = AnprEvent::factory()->create([
            'plate_number' => 'VERIFY02',
            'confidence' => 0.9200,
        ]);

        $hash = app(BlockchainHashService::class)->hashEntity($event);

        return BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => $event->id,
            'proof_type' => 'entity_created',
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
            'record_hash' => $hash['record_hash'],
            'contract_address' => '0x'.str_repeat('a', 40),
        ]);
    }

    private function fakeVerifyRpc(bool $found): void
    {
        Http::fake(function ($request) use ($found) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $found
                        ? '0x'.str_repeat('0', 63).'1'
                        : '0x'.str_repeat('0', 64),
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });
    }
}

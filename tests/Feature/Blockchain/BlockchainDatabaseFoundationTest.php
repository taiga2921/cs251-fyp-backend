<?php

namespace Tests\Feature\Blockchain;

use App\Http\Resources\BlockchainRecordResource;
use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Models\BlockchainVerification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class BlockchainDatabaseFoundationTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_migrate_fresh_succeeds(): void
    {
        $this->assertTrue(
            \Schema::hasTable('blockchain_records')
            && \Schema::hasTable('blockchain_jobs')
            && \Schema::hasTable('blockchain_verifications')
        );
        $this->assertTrue(\Schema::hasColumns('blockchain_records', [
            'record_hash',
            'proof_type',
            'canonical_version',
            'payload_summary',
            'confirmations',
            'last_error',
        ]));
    }

    public function test_blockchain_record_factory_creates_records(): void
    {
        $record = BlockchainRecord::factory()->create();

        $this->assertDatabaseHas('blockchain_records', [
            'id' => $record->id,
            'entity_type' => $record->entity_type,
            'record_hash' => $record->record_hash,
            'status' => 'pending',
        ]);
        $this->assertSame(64, strlen($record->record_hash));
    }

    public function test_blockchain_job_factory_creates_jobs_linked_to_records(): void
    {
        $job = BlockchainJob::factory()->create();

        $this->assertDatabaseHas('blockchain_jobs', [
            'id' => $job->id,
            'blockchain_record_id' => $job->blockchain_record_id,
        ]);
        $this->assertInstanceOf(BlockchainRecord::class, $job->blockchainRecord);
    }

    public function test_blockchain_verification_factory_creates_verifications_linked_to_records(): void
    {
        $verification = BlockchainVerification::factory()->create();

        $this->assertDatabaseHas('blockchain_verifications', [
            'id' => $verification->id,
            'blockchain_record_id' => $verification->blockchain_record_id,
        ]);
        $this->assertInstanceOf(BlockchainRecord::class, $verification->blockchainRecord);
    }

    public function test_blockchain_record_has_many_jobs_and_verifications(): void
    {
        $record = BlockchainRecord::factory()->create();
        $job = BlockchainJob::factory()->for($record)->create();
        $verification = BlockchainVerification::factory()->for($record)->create();

        $record->load(['jobs', 'verifications']);

        $this->assertTrue($record->jobs->contains($job));
        $this->assertTrue($record->verifications->contains($verification));
    }

    public function test_blockchain_verification_belongs_to_verified_by_user(): void
    {
        $user = User::factory()->create();
        $verification = BlockchainVerification::factory()->forUser($user)->create();

        $this->assertTrue($verification->verifiedBy->is($user));
    }

    public function test_payload_summary_casts_to_array(): void
    {
        $record = BlockchainRecord::factory()->create([
            'payload_summary' => ['module' => 'anpr', 'summary' => 'safe demo metadata'],
        ]);

        $record->refresh();

        $this->assertIsArray($record->payload_summary);
        $this->assertSame('anpr', $record->payload_summary['module']);
    }

    public function test_status_scopes_return_expected_rows(): void
    {
        BlockchainRecord::factory()->pending()->create();
        BlockchainRecord::factory()->queued()->create();
        BlockchainRecord::factory()->create(['status' => 'processing']);
        BlockchainRecord::factory()->submitted()->create();
        BlockchainRecord::factory()->confirmed()->create();
        BlockchainRecord::factory()->failed()->create();

        $this->assertSame(1, BlockchainRecord::query()->pending()->count());
        $this->assertSame(1, BlockchainRecord::query()->queued()->count());
        $this->assertSame(1, BlockchainRecord::query()->processing()->count());
        $this->assertSame(1, BlockchainRecord::query()->submitted()->count());
        $this->assertSame(1, BlockchainRecord::query()->confirmed()->count());
        $this->assertSame(1, BlockchainRecord::query()->failed()->count());
    }

    public function test_unique_proof_rule_prevents_duplicate_records(): void
    {
        $attributes = [
            'entity_type' => 'anpr_event',
            'entity_id' => '01940000-0000-7000-8000-000000000099',
            'proof_type' => 'entity_created',
            'canonical_version' => 'v1',
            'environment' => 'local',
        ];

        BlockchainRecord::factory()->create($attributes);

        $this->expectException(QueryException::class);
        BlockchainRecord::factory()->create($attributes);
    }

    public function test_blockchain_record_resource_exposes_safe_fields_only(): void
    {
        $record = BlockchainRecord::factory()
            ->confirmed()
            ->create([
                'payload_summary' => ['module' => 'anpr', 'summary' => 'safe summary'],
            ])
            ->load(['jobs', 'verifications']);

        BlockchainJob::factory()->for($record)->successful()->create();
        BlockchainVerification::factory()->for($record)->valid()->create();

        $record->load(['jobs', 'verifications']);
        $payload = (new BlockchainRecordResource($record))->resolve();

        $this->assertArrayHasKey('record_hash', $payload);
        $this->assertArrayHasKey('payload_summary', $payload);
        $this->assertArrayHasKey('jobs', $payload);
        $this->assertArrayHasKey('verifications', $payload);
        $this->assertArrayNotHasKey('hash', $payload);
        $this->assertArrayNotHasKey('error_message', $payload);
        $this->assertArrayNotHasKey('private_key', $payload);
        $this->assertArrayNotHasKey('rpc_url', $payload);
        $this->assertSame('safe summary', $payload['payload_summary']['summary']);
    }

    public function test_existing_blockchain_record_show_returns_related_jobs_and_verifications(): void
    {
        $admin = $this->adminUser();
        $record = BlockchainRecord::factory()->confirmed()->create();
        $job = BlockchainJob::factory()->for($record)->successful()->create();
        $verifier = User::factory()->create();
        $verification = BlockchainVerification::factory()
            ->for($record)
            ->forUser($verifier)
            ->valid()
            ->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.record_hash', $record->record_hash)
            ->assertJsonPath('data.proof_type', $record->proof_type)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.jobs.0.id', $job->id)
            ->assertJsonPath('data.verifications.0.id', $verification->id)
            ->assertJsonPath('data.verifications.0.verified_by_user.name', $verifier->name)
            ->assertJsonMissing(['data.hash' => $record->record_hash]);
    }

    public function test_guard_cannot_access_blockchain_record_endpoints(): void
    {
        $guard = $this->guardUser();
        $record = BlockchainRecord::factory()->create();

        $this->actingAs($guard, 'api')
            ->getJson('/api/blockchain-records')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Only administrators and security operators may perform this action.'
            );

        $this->actingAs($guard, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertForbidden();
    }

    public function test_admin_can_list_blockchain_records_with_filters_and_pagination(): void
    {
        $admin = $this->adminUser();

        $matching = BlockchainRecord::factory()->pending()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => '01940000-0000-7000-8000-000000000101',
            'network' => 'ganache',
            'environment' => 'local',
        ]);
        BlockchainRecord::factory()->confirmed()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => '01940000-0000-7000-8000-000000000102',
            'network' => 'sepolia',
            'environment' => 'staging',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records?status=pending&network=ganache&environment=local&entity_type=anpr_event&entity_id='.$matching->entity_id.'&sort_by=created_at&sort_order=desc&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonMissing(['data.0.private_key' => true])
            ->assertJsonMissing(['data.0.rpc_url' => true]);
    }

    public function test_security_operator_can_access_blockchain_record_endpoints(): void
    {
        $operator = $this->securityOperatorUser();
        $record = BlockchainRecord::factory()->pending()->create();

        $this->actingAs($operator, 'api')
            ->getJson('/api/blockchain-records')
            ->assertOk()
            ->assertJsonPath('data.0.id', $record->id);

        $this->actingAs($operator, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.id', $record->id);
    }

    public function test_record_created_by_service_is_visible_via_read_api(): void
    {
        $admin = $this->adminUser();
        $event = \App\Models\AnprEvent::factory()->create([
            'plate_number' => 'M5TEST01',
            'confidence' => 0.8800,
            'detection_time' => now(),
        ]);

        $record = app(\App\Services\Blockchain\BlockchainRecordService::class)->createForEntity($event);

        $this->actingAs($admin, 'api')
            ->getJson('/api/blockchain-records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.record_hash', $record->record_hash)
            ->assertJsonPath('data.payload_summary.plate_number', 'M5TEST01')
            ->assertJsonMissing(['data.canonical_json' => true])
            ->assertJsonMissing(['data.private_key' => true]);
    }
}

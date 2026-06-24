<?php

namespace Tests\Feature\Blockchain;

use App\Jobs\AnchorBlockchainRecordJob;
use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\BlockchainRecord;
use App\Models\Camera;
use App\Services\Blockchain\BlockchainHashService;
use App\Services\Blockchain\BlockchainRecordService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AnprBlockchainIntegrationTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    private string $imageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->imageRoot = storage_path('framework/testing/anpr-blockchain');
        File::ensureDirectoryExists($this->imageRoot);
        config(['anpr.image_roots' => [$this->imageRoot]]);

        config([
            'blockchain.canonical_version' => 'v1',
            'blockchain.hash_algorithm' => 'sha256',
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->imageRoot)) {
            File::deleteDirectory($this->imageRoot);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function anprEventPayload(?string $cameraId = null): array
    {
        return [
            'camera_id' => $cameraId ?? Camera::factory()->create()->id,
            'plate_number' => 'M10-001',
            'confidence' => 0.91,
            'detection_time' => now()->toIso8601String(),
            'is_valid' => true,
        ];
    }

    public function test_anpr_event_store_with_blockchain_disabled_does_not_create_proof_or_dispatch_job(): void
    {
        Bus::fake();
        Http::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plate_number', 'M10001');

        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('anpr_events', [
            'id' => $eventId,
            'plate_number' => 'M10001',
        ]);
        $this->assertDatabaseCount('blockchain_records', 0);
        Bus::assertNotDispatched(AnchorBlockchainRecordJob::class);
        Http::assertNothingSent();
    }

    public function test_anpr_event_store_with_blockchain_enabled_creates_proof_and_dispatches_job(): void
    {
        Bus::fake();
        Http::fake();
        config(['blockchain.enabled' => true]);

        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload())
            ->assertCreated();

        $eventId = $response->json('data.id');
        $event = AnprEvent::query()->findOrFail($eventId);

        $this->assertDatabaseHas('blockchain_records', [
            'entity_type' => 'anpr_event',
            'entity_id' => $eventId,
            'proof_type' => 'entity_created',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $record = BlockchainRecord::query()
            ->where('entity_type', 'anpr_event')
            ->where('entity_id', $eventId)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame($record->id, $event->blockchain_record_id);
        $this->assertSame('queued', $record->status);

        Bus::assertDispatched(AnchorBlockchainRecordJob::class, function (AnchorBlockchainRecordJob $job) use ($record): bool {
            return $job->blockchainRecordId === $record->id;
        });
        Http::assertNothingSent();
    }

    public function test_anpr_event_store_rejects_client_supplied_blockchain_record_id(): void
    {
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $existingRecord = BlockchainRecord::factory()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', array_merge($this->anprEventPayload(), [
                'blockchain_record_id' => $existingRecord->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_anpr_image_upload_with_blockchain_disabled_does_not_dispatch_job(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('full.jpg', 200, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $file,
            ])
            ->assertOk();

        $this->assertDatabaseCount('blockchain_records', 0);
        Bus::assertNotDispatched(AnchorBlockchainRecordJob::class);
    }

    public function test_anpr_image_upload_with_blockchain_enabled_creates_evidence_proof_and_dispatches_job(): void
    {
        Bus::fake();
        Http::fake();
        config(['blockchain.enabled' => true]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('full.jpg', 200, 'image/jpeg');

        $response = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $file,
            ])
            ->assertOk();

        $imageId = $response->json('data.id');
        $image = AnprImage::query()->findOrFail($imageId);

        $this->assertDatabaseHas('blockchain_records', [
            'entity_type' => 'anpr_image',
            'entity_id' => $imageId,
            'proof_type' => 'evidence_file',
        ]);

        $record = BlockchainRecord::query()
            ->where('entity_type', 'anpr_image')
            ->where('entity_id', $imageId)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame('queued', $record->status);

        $expectedHash = app(BlockchainHashService::class)->hashEntity($image, 'evidence_file');
        $this->assertSame($expectedHash['record_hash'], $record->record_hash);
        $this->assertSame('file', $record->payload_summary['evidence_hash_source'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($record->payload_summary['file_sha256'] ?? ''));

        Bus::assertDispatched(AnchorBlockchainRecordJob::class, function (AnchorBlockchainRecordJob $job) use ($record): bool {
            return $job->blockchainRecordId === $record->id;
        });
        Http::assertNothingSent();

        $response
            ->assertJsonPath('data.blockchain_proof.entity_type', 'anpr_image')
            ->assertJsonPath('data.blockchain_proof.proof_type', 'evidence_file')
            ->assertJsonPath('data.blockchain_proof.status', 'queued')
            ->assertJsonMissingPath('data.blockchain_proof.record_hash');
    }

    public function test_anpr_image_show_includes_blockchain_proof_when_enabled(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => true]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('plate.jpg', 200, 'image/jpeg');

        $uploadResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'plate',
                'image' => $file,
            ])
            ->assertOk();

        $imageId = $uploadResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-images/'.$imageId)
            ->assertOk()
            ->assertJsonPath('data.blockchain_proof.entity_type', 'anpr_image')
            ->assertJsonPath('data.blockchain_proof.proof_type', 'evidence_file')
            ->assertJsonPath('data.blockchain_proof.status', 'queued');
    }

    public function test_direct_anpr_image_responses_omit_blockchain_proof_when_disabled(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $file = UploadedFile::fake()->create('full.jpg', 200, 'image/jpeg');

        $uploadResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.blockchain_proof', null);

        $imageId = $uploadResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-images/'.$imageId)
            ->assertOk()
            ->assertJsonPath('data.blockchain_proof', null);
    }

    public function test_automatic_blockchain_proof_failure_logs_sanitized_warning_and_preserves_anpr_event(): void
    {
        Log::spy();
        Bus::fake();
        config([
            'blockchain.enabled' => true,
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);

        $privateKey = (string) config('blockchain.private_key');
        $sensitiveMessage = 'RPC failed at http://127.0.0.1:7545 key='.$privateKey;

        $this->mock(BlockchainRecordService::class, function ($mock) use ($sensitiveMessage): void {
            $mock->shouldReceive('createForEntity')
                ->andThrow(new RuntimeException($sensitiveMessage));
        });

        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('anpr_events', [
            'id' => $response->json('data.id'),
            'plate_number' => 'M10001',
        ]);
        $this->assertDatabaseCount('blockchain_records', 0);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($privateKey, $sensitiveMessage): bool {
                return $message === 'Automatic blockchain proof creation failed for ANPR entity.'
                    && isset($context['error'])
                    && is_string($context['error'])
                    && ! str_contains($context['error'], 'http://127.0.0.1:7545')
                    && ! str_contains($context['error'], $privateKey)
                    && ! str_contains($context['error'], $sensitiveMessage)
                    && str_contains($context['error'], '[rpc-url-redacted]');
            });
    }

    public function test_anpr_event_show_exposes_safe_blockchain_proof_fields(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => true]);

        $admin = $this->adminUser();

        $createResponse = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload())
            ->assertCreated();

        $eventId = $createResponse->json('data.id');
        $file = UploadedFile::fake()->create('plate.jpg', 200, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$eventId}/images/upload", [
                'image_type' => 'plate',
                'image' => $file,
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events/'.$eventId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.blockchain_proof.entity_type', 'anpr_event')
            ->assertJsonPath('data.blockchain_proof.proof_type', 'entity_created')
            ->assertJsonPath('data.image_blockchain_proof_summary.count', 1);

        $response
            ->assertJsonStructure([
                'data' => [
                    'blockchain_proof' => [
                        'id',
                        'entity_type',
                        'proof_type',
                        'network',
                        'environment',
                        'status',
                    ],
                ],
            ])
            ->assertJsonMissingPath('data.blockchain_proof.payload_summary')
            ->assertJsonMissingPath('data.blockchain_proof.record_hash');
    }
}

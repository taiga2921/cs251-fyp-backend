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

    public function test_anpr_image_upload_replacement_blocked_after_blockchain_evidence_proof_exists(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => true]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $firstFile = UploadedFile::fake()->create('full-first.jpg', 200, 'image/jpeg');

        $firstResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $firstFile,
            ])
            ->assertOk();

        $imageId = $firstResponse->json('data.id');
        $originalPath = $firstResponse->json('data.file_path');
        $originalAbsolute = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $originalPath);

        $this->assertDatabaseHas('blockchain_records', [
            'entity_type' => 'anpr_image',
            'entity_id' => $imageId,
            'proof_type' => 'evidence_file',
        ]);
        $this->assertDatabaseCount('blockchain_records', 1);

        $secondFile = UploadedFile::fake()->create('full-second.jpg', 300, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $secondFile,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->assertDatabaseCount('blockchain_records', 1);
        $this->assertDatabaseHas('anpr_images', [
            'id' => $imageId,
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => $originalPath,
        ]);
        $this->assertFileExists($originalAbsolute);
    }

    public function test_anpr_image_upload_replacement_allowed_before_blockchain_evidence_proof_exists(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $firstFile = UploadedFile::fake()->create('full-first.jpg', 200, 'image/jpeg');
        $secondFile = UploadedFile::fake()->create('full-second.jpg', 200, 'image/jpeg');

        $firstResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $firstFile,
            ])
            ->assertOk();

        $firstId = $firstResponse->json('data.id');
        $firstPath = $firstResponse->json('data.file_path');
        $firstAbsolute = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $firstPath);

        $secondResponse = $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $secondFile,
            ])
            ->assertOk();

        $secondId = $secondResponse->json('data.id');
        $secondPath = $secondResponse->json('data.file_path');

        $this->assertSame($firstId, $secondId);
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertDatabaseCount('anpr_images', 1);
        $this->assertDatabaseCount('blockchain_records', 0);
        $this->assertFileDoesNotExist($firstAbsolute);

        $secondAbsolute = $this->imageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $secondPath);
        $this->assertFileExists($secondAbsolute);
    }

    public function test_anpr_image_upload_replacement_blocked_when_proof_exists_even_if_blockchain_disabled(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $image = AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'plate',
            'file_path' => 'events/'.$event->id.'/plate_existing.jpg',
            'file_size' => 200,
            'resolution' => '640x480',
        ]);

        $absoluteDirectory = $this->imageRoot.DIRECTORY_SEPARATOR.'events'.DIRECTORY_SEPARATOR.$event->id;
        File::ensureDirectoryExists($absoluteDirectory);
        File::put($absoluteDirectory.DIRECTORY_SEPARATOR.'plate_existing.jpg', 'existing-evidence');

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $replacementFile = UploadedFile::fake()->create('plate-replacement.jpg', 200, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'plate',
                'image' => $replacementFile,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->assertDatabaseHas('anpr_images', [
            'id' => $image->id,
            'file_path' => 'events/'.$event->id.'/plate_existing.jpg',
        ]);
        $this->assertDatabaseCount('blockchain_records', 1);
    }

    public function test_anpr_image_update_blocks_canonical_field_changes_after_blockchain_evidence_proof_exists(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $image = AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => 'events/'.$event->id.'/full.jpg',
            'file_size' => 1024,
            'resolution' => '1280x720',
        ]);

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'file_path' => 'events/'.$event->id.'/full-replaced.jpg',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'file_size' => 2048,
            ])
            ->assertStatus(409);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'resolution' => '1920x1080',
            ])
            ->assertStatus(409);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'image_type' => 'plate',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('anpr_images', [
            'id' => $image->id,
            'file_path' => 'events/'.$event->id.'/full.jpg',
            'file_size' => 1024,
            'resolution' => '1280x720',
            'image_type' => 'full',
        ]);
    }

    public function test_anpr_image_update_allows_non_canonical_metadata_after_blockchain_evidence_proof_exists(): void
    {
        $admin = $this->adminUser();
        $image = AnprImage::factory()->create([
            'expires_at' => null,
        ]);

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $expiresAt = now()->addDays(30)->toIso8601String();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'expires_at' => $expiresAt,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($image->fresh()->expires_at);
    }

    public function test_anpr_image_destroy_blocked_after_blockchain_evidence_proof_exists(): void
    {
        $admin = $this->adminUser();
        $image = AnprImage::factory()->create();

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/anpr-images/'.$image->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->assertDatabaseHas('anpr_images', ['id' => $image->id]);
    }

    public function test_anpr_event_destroy_is_blocked_when_event_has_proofed_image(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $relativePath = 'events/'.$event->id.'/full.jpg';
        $absoluteDirectory = $this->imageRoot.DIRECTORY_SEPARATOR.'events'.DIRECTORY_SEPARATOR.$event->id;
        File::ensureDirectoryExists($absoluteDirectory);
        File::put($absoluteDirectory.DIRECTORY_SEPARATOR.'full.jpg', 'proofed-evidence');

        $image = AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => $relativePath,
        ]);

        $record = BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/anpr-events/'.$event->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'ANPR event cannot be deleted because it contains immutable blockchain-proofed evidence.',
            );

        $this->assertDatabaseHas('anpr_events', ['id' => $event->id]);
        $this->assertDatabaseHas('anpr_images', ['id' => $image->id]);
        $this->assertDatabaseHas('blockchain_records', ['id' => $record->id]);
        $this->assertFileExists($absoluteDirectory.DIRECTORY_SEPARATOR.'full.jpg');
    }

    public function test_anpr_event_destroy_is_blocked_when_event_has_event_creation_proof(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $record = BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_event',
            'entity_id' => $event->id,
            'proof_type' => 'entity_created',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/anpr-events/'.$event->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ANPR event is immutable after blockchain proof creation.');

        $this->assertDatabaseHas('anpr_events', ['id' => $event->id]);
        $this->assertDatabaseHas('blockchain_records', ['id' => $record->id]);
    }

    public function test_upload_replacement_is_blocked_when_any_matching_image_type_row_has_proof(): void
    {
        Bus::fake();
        config(['blockchain.enabled' => false]);

        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $unproofedImage = AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => 'events/'.$event->id.'/full-unproofed.jpg',
            'file_size' => 100,
        ]);

        $proofedImage = AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => 'events/'.$event->id.'/full-proofed.jpg',
            'file_size' => 200,
        ]);

        $absoluteDirectory = $this->imageRoot.DIRECTORY_SEPARATOR.'events'.DIRECTORY_SEPARATOR.$event->id;
        File::ensureDirectoryExists($absoluteDirectory);
        File::put($absoluteDirectory.DIRECTORY_SEPARATOR.'full-unproofed.jpg', 'unproofed-bytes');
        File::put($absoluteDirectory.DIRECTORY_SEPARATOR.'full-proofed.jpg', 'proofed-bytes');

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $proofedImage->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $replacementFile = UploadedFile::fake()->create('full-replacement.jpg', 300, 'image/jpeg');

        $this->actingAs($admin, 'api')
            ->post("/api/anpr-events/{$event->id}/images/upload", [
                'image_type' => 'full',
                'image' => $replacementFile,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->assertDatabaseCount('blockchain_records', 1);
        $this->assertDatabaseHas('anpr_images', [
            'id' => $unproofedImage->id,
            'file_path' => 'events/'.$event->id.'/full-unproofed.jpg',
        ]);
        $this->assertDatabaseHas('anpr_images', [
            'id' => $proofedImage->id,
            'file_path' => 'events/'.$event->id.'/full-proofed.jpg',
        ]);
        $this->assertFileExists($absoluteDirectory.DIRECTORY_SEPARATOR.'full-unproofed.jpg');
        $this->assertFileExists($absoluteDirectory.DIRECTORY_SEPARATOR.'full-proofed.jpg');
    }

    public function test_anpr_event_id_update_is_blocked_after_image_proof_exists(): void
    {
        $admin = $this->adminUser();
        $originalEvent = AnprEvent::factory()->create();
        $otherEvent = AnprEvent::factory()->create();
        $image = AnprImage::factory()->create([
            'anpr_event_id' => $originalEvent->id,
            'image_type' => 'full',
            'file_path' => 'events/'.$originalEvent->id.'/full.jpg',
        ]);

        BlockchainRecord::factory()->create([
            'entity_type' => 'anpr_image',
            'entity_id' => $image->id,
            'proof_type' => 'evidence_file',
            'network' => 'ganache',
            'environment' => 'local',
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-images/'.$image->id, [
                'anpr_event_id' => $otherEvent->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'ANPR image evidence is immutable after blockchain proof creation.');

        $this->assertDatabaseHas('anpr_images', [
            'id' => $image->id,
            'anpr_event_id' => $originalEvent->id,
        ]);
    }
}

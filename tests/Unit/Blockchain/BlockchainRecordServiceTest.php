<?php

namespace Tests\Unit\Blockchain;

use App\Models\AnprEvent;
use App\Models\BlockchainRecord;
use App\Models\Camera;
use App\Services\Blockchain\BlockchainHashService;
use App\Services\Blockchain\BlockchainRecordService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockchainRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlockchainRecordService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.enabled' => false,
            'blockchain.canonical_version' => 'v1',
            'blockchain.hash_algorithm' => 'sha256',
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
        ]);

        $this->service = new BlockchainRecordService(new BlockchainHashService);
    }

    public function test_creates_pending_blockchain_record_for_anpr_event(): void
    {
        $event = $this->createAnprEvent();

        $record = $this->service->createForEntity($event);

        $this->assertInstanceOf(BlockchainRecord::class, $record);
        $this->assertSame('pending', $record->status);
        $this->assertDatabaseHas('blockchain_records', [
            'id' => $record->id,
            'entity_type' => 'anpr_event',
            'entity_id' => $event->id,
            'status' => 'pending',
        ]);
    }

    public function test_persists_m4_hash_metadata_on_created_record(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
        ]);

        $expectedHash = (new BlockchainHashService)->hashEntity($event);
        $record = $this->service->createForEntity($event);

        $this->assertSame($expectedHash['record_hash'], $record->record_hash);
        $this->assertSame('v1', $record->canonical_version);
        $this->assertSame('sha256', $record->hash_algorithm);
        $this->assertSame('entity_created', $record->proof_type);
    }

    public function test_stores_configured_network_environment_and_chain_metadata(): void
    {
        config([
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('b', 40),
        ]);

        $record = $this->service->createForEntity($this->createAnprEvent());

        $this->assertSame('ganache', $record->network);
        $this->assertSame('local', $record->environment);
        $this->assertSame(1337, $record->chain_id);
        $this->assertSame('0x'.str_repeat('b', 40), $record->contract_address);
    }

    #[DataProvider('configuredChainIdNormalizationProvider')]
    public function test_configured_chain_id_is_normalized_before_persisting(mixed $configuredValue, ?int $expectedChainId): void
    {
        config(['blockchain.chain_id' => $configuredValue]);

        $record = $this->service->createForEntity($this->createAnprEvent());

        $this->assertSame($expectedChainId, $record->chain_id);
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function configuredChainIdNormalizationProvider(): array
    {
        return [
            'malformed string' => ['not-a-number', null],
            'empty string' => ['', null],
            'zero integer' => [0, null],
            'negative integer' => [-1, null],
            'positive numeric string' => ['1337', 1337],
        ];
    }

    public function test_creates_safe_payload_summary_for_anpr_event(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
            'is_valid' => true,
            'is_flagged' => false,
        ]);

        $record = $this->service->createForEntity($event);

        $this->assertSame([
            'module' => 'anpr',
            'entity_type' => 'anpr_event',
            'entity_id' => (string) $event->id,
            'proof_type' => 'entity_created',
            'plate_number' => 'ABC1234',
            'camera_id' => (string) $event->camera_id,
            'detection_time' => '2026-06-21T10:00:00Z',
            'confidence' => '0.9200',
            'is_valid' => true,
            'is_flagged' => false,
        ], $record->payload_summary);
    }

    public function test_payload_summary_excludes_sensitive_or_volatile_fields(): void
    {
        $event = $this->createAnprEvent([
            'latitude' => 3.1415927,
            'longitude' => 101.6868550,
        ]);

        $record = $this->service->createForEntity($event);
        $summary = $record->payload_summary;

        $this->assertIsArray($summary);
        $this->assertArrayNotHasKey('canonical_json', $summary);
        $this->assertArrayNotHasKey('private_key', $summary);
        $this->assertArrayNotHasKey('rpc_url', $summary);
        $this->assertArrayNotHasKey('latitude', $summary);
        $this->assertArrayNotHasKey('longitude', $summary);
        $this->assertArrayNotHasKey('vehicle_id', $summary);
    }

    public function test_duplicate_proof_creation_is_idempotent(): void
    {
        $event = $this->createAnprEvent();

        $first = $this->service->createForEntity($event);
        $second = $this->service->createForEntity($event);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, BlockchainRecord::query()->count());
    }

    public function test_different_proof_types_create_different_records(): void
    {
        $event = $this->createAnprEvent();

        $created = $this->service->createForEntity($event, 'entity_created');
        $updated = $this->service->createForEntity($event, 'entity_updated');

        $this->assertNotSame($created->id, $updated->id);
        $this->assertSame('entity_created', $created->proof_type);
        $this->assertSame('entity_updated', $updated->proof_type);
        $this->assertSame(2, BlockchainRecord::query()->count());
    }

    public function test_unsupported_entities_surface_hash_service_exception(): void
    {
        $camera = Camera::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported entity class for blockchain hashing: App\Models\Camera');

        $this->service->createForEntity($camera);
    }

    public function test_updates_anpr_event_blockchain_record_id_when_empty(): void
    {
        $event = $this->createAnprEvent([
            'blockchain_record_id' => null,
        ]);

        $record = $this->service->createForEntity($event);

        $event->refresh();

        $this->assertSame($record->id, $event->blockchain_record_id);
    }

    public function test_does_not_overwrite_existing_anpr_event_blockchain_record_id(): void
    {
        $event = $this->createAnprEvent([
            'blockchain_record_id' => null,
        ]);
        $existingLink = BlockchainRecord::factory()->create();
        $event->update(['blockchain_record_id' => $existingLink->id]);

        $record = $this->service->createForEntity($event);

        $event->refresh();

        $this->assertSame($existingLink->id, $event->blockchain_record_id);
        $this->assertNotSame($existingLink->id, $record->id);
    }

    public function test_does_not_dispatch_jobs_or_call_ethereum(): void
    {
        Queue::fake();

        $this->service->createForEntity($this->createAnprEvent());

        Queue::assertNothingPushed();
        $this->assertSame(0, BlockchainRecord::query()->whereNotNull('tx_hash')->count());
    }

    public function test_creates_pending_records_when_blockchain_is_disabled(): void
    {
        config(['blockchain.enabled' => false]);

        $record = $this->service->createForEntity($this->createAnprEvent());

        $this->assertSame('pending', $record->status);
        $this->assertNull($record->tx_hash);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAnprEvent(array $overrides = []): AnprEvent
    {
        return AnprEvent::factory()->create($overrides);
    }
}

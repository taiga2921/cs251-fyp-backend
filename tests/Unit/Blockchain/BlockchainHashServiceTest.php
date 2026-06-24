<?php

namespace Tests\Unit\Blockchain;

use App\Models\AnprEvent;
use App\Models\BlockchainRecord;
use App\Models\Camera;
use App\Models\Vehicle;
use App\Services\Blockchain\BlockchainHashService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BlockchainHashServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlockchainHashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.canonical_version' => 'v1',
            'blockchain.hash_algorithm' => 'sha256',
        ]);

        $this->service = new BlockchainHashService;
    }

    public function test_same_raw_payload_produces_same_sha256_hash_repeatedly(): void
    {
        $payload = [
            'entity_type' => 'anpr_event',
            'entity_id' => 'event-1',
            'proof_type' => 'entity_created',
            'camera_id' => 'camera-1',
            'plate_number' => 'ABC1234',
            'confidence' => '0.9200',
            'detection_time' => '2026-06-21T10:00:00Z',
            'is_flagged' => false,
            'is_valid' => true,
        ];

        $first = $this->service->hashPayload($payload);
        $second = $this->service->hashPayload($payload);

        $this->assertSame($first['record_hash'], $second['record_hash']);
        $this->assertSame($first['canonical_json'], $second['canonical_json']);
    }

    public function test_same_anpr_event_produces_same_hash_repeatedly(): void
    {
        $event = $this->createAnprEvent();

        $first = $this->service->hashEntity($event);
        $second = $this->service->hashEntity($event);

        $this->assertSame($first['record_hash'], $second['record_hash']);
    }

    public function test_different_proof_types_produce_different_hashes_for_same_anpr_event(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
        ]);

        $created = $this->service->hashEntity($event, 'entity_created');
        $updated = $this->service->hashEntity($event, 'entity_updated');

        $this->assertSame('entity_created', $created['canonical_payload']['proof_type']);
        $this->assertSame('entity_updated', $updated['canonical_payload']['proof_type']);
        $this->assertNotSame($created['record_hash'], $updated['record_hash']);
    }

    public function test_anpr_event_hash_changes_when_proof_field_changes(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
        ]);

        $originalHash = $this->service->hashEntity($event)['record_hash'];

        $event->plate_number = 'XYZ9999';
        $this->assertNotSame($originalHash, $this->service->hashEntity($event)['record_hash']);

        $event->plate_number = 'ABC1234';
        $event->confidence = 0.8100;
        $this->assertNotSame($originalHash, $this->service->hashEntity($event)['record_hash']);

        $event->confidence = 0.9200;
        $event->detection_time = Carbon::parse('2026-06-21T11:00:00Z');
        $this->assertNotSame($originalHash, $this->service->hashEntity($event)['record_hash']);
    }

    public function test_anpr_event_hash_does_not_change_when_excluded_fields_change(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
            'latitude' => 3.1415927,
            'longitude' => 101.6868550,
            'vehicle_id' => null,
            'blockchain_record_id' => null,
        ]);

        $originalHash = $this->service->hashEntity($event)['record_hash'];

        $event->created_at = Carbon::parse('2020-01-01T00:00:00Z');
        $event->updated_at = Carbon::parse('2030-01-01T00:00:00Z');
        $event->vehicle_id = Vehicle::factory()->create()->id;
        $event->blockchain_record_id = BlockchainRecord::factory()->create()->id;
        $event->latitude = 1.2345678;
        $event->longitude = 103.8765432;

        $this->assertSame($originalHash, $this->service->hashEntity($event)['record_hash']);
    }

    public function test_hash_result_contains_expected_metadata_fields(): void
    {
        $event = $this->createAnprEvent();
        $result = $this->service->hashEntity($event);

        $this->assertArrayHasKey('canonical_version', $result);
        $this->assertArrayHasKey('hash_algorithm', $result);
        $this->assertArrayHasKey('canonical_payload', $result);
        $this->assertArrayHasKey('canonical_json', $result);
        $this->assertArrayHasKey('record_hash', $result);

        $this->assertSame('v1', $result['canonical_version']);
        $this->assertSame('sha256', $result['hash_algorithm']);
        $this->assertIsArray($result['canonical_payload']);
        $this->assertIsString($result['canonical_json']);
    }

    public function test_record_hash_is_lowercase_sha256_hex_string(): void
    {
        $result = $this->service->hashPayload([
            'entity_type' => 'anpr_event',
            'entity_id' => 'event-1',
            'proof_type' => 'entity_created',
            'camera_id' => 'camera-1',
            'plate_number' => 'ABC1234',
            'confidence' => '0.9200',
            'detection_time' => '2026-06-21T10:00:00Z',
            'is_flagged' => false,
            'is_valid' => true,
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['record_hash']);
        $this->assertSame(hash('sha256', $result['canonical_json']), $result['record_hash']);
    }

    public function test_unsupported_hash_algorithm_throws_clear_exception(): void
    {
        config(['blockchain.hash_algorithm' => 'md5']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported blockchain hash algorithm: md5');

        $this->service->hashPayload(['entity_type' => 'anpr_event']);
    }

    public function test_unsupported_entity_class_throws_clear_exception(): void
    {
        $camera = Camera::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported entity class for blockchain hashing: App\Models\Camera');

        $this->service->hashEntity($camera);
    }

    public function test_anpr_event_payload_excludes_volatile_and_sensitive_fields(): void
    {
        $event = $this->createAnprEvent([
            'plate_number' => 'ABC1234',
            'confidence' => 0.9200,
            'detection_time' => Carbon::parse('2026-06-21T10:00:00Z'),
            'latitude' => 3.1415927,
            'longitude' => 101.6868550,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'blockchain_record_id' => BlockchainRecord::factory()->create()->id,
        ]);

        $payload = $this->service->buildAnprEventPayload($event);

        $this->assertSame([
            'entity_type' => 'anpr_event',
            'entity_id' => (string) $event->id,
            'proof_type' => 'entity_created',
            'camera_id' => (string) $event->camera_id,
            'plate_number' => 'ABC1234',
            'confidence' => '0.9200',
            'detection_time' => '2026-06-21T10:00:00Z',
            'is_flagged' => $event->is_flagged,
            'is_valid' => $event->is_valid,
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAnprEvent(array $overrides = []): AnprEvent
    {
        return AnprEvent::factory()->create($overrides);
    }
}

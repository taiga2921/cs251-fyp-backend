<?php

namespace Tests\Feature;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use App\Models\Camera;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

/**
 * M14 regression coverage for ANPR APIs not fully asserted in M12/M13 suites.
 */
class AnprM14RegressionTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function aiCompatiblePayload(string $plateNumber, ?string $cameraId = null): array
    {
        return [
            'camera_id' => $cameraId ?? Camera::factory()->create()->id,
            'plate_number' => $plateNumber,
            'confidence' => 0.91,
            'detection_time' => now()->toIso8601String(),
            'is_valid' => true,
            'is_flagged' => false,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    public function test_anpr_event_store_accepts_ai_compatible_payload_without_vehicle_id(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->aiCompatiblePayload('NEW-AI-1'))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'plate_number',
                    'vehicle_id',
                    'vehicle',
                ],
            ]);

        $this->assertArrayNotHasKey('vehicle_id', $this->aiCompatiblePayload('ignored'));
        $this->assertDatabaseHas('anpr_events', [
            'id' => $response->json('data.id'),
            'plate_number' => 'NEWAI1',
        ]);
    }

    public function test_anpr_event_store_returns_field_level_validation_errors(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', [
                'plate_number' => 'ABC1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.');

        $errors = $response->json('data.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('camera_id', $errors);
        $this->assertArrayHasKey('detection_time', $errors);
    }

    public function test_anpr_events_require_authentication(): void
    {
        $this->getJson('/api/anpr-events')->assertUnauthorized();
        $this->postJson('/api/anpr-events', [])->assertUnauthorized();
    }

    public function test_anpr_events_index_returns_images_count_without_full_image_rows(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        AnprImage::factory()->count(2)->create(['anpr_event_id' => $event->id]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events')
            ->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $event->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['images_count']);
        $this->assertArrayNotHasKey('images', $row);
    }

    public function test_anpr_event_show_includes_vehicle_camera_and_images(): void
    {
        $admin = $this->adminUser();
        $camera = Camera::factory()->create(['name' => 'Entry Camera']);
        $vehicle = Vehicle::factory()->create(['plate_number' => 'LINK9001', 'source' => 'manual']);
        $event = AnprEvent::factory()->create([
            'camera_id' => $camera->id,
            'vehicle_id' => $vehicle->id,
            'plate_number' => 'LINK9001',
        ]);
        AnprImage::factory()->create([
            'anpr_event_id' => $event->id,
            'image_type' => 'full',
            'file_path' => 'evidence/full.jpg',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.camera.id', $camera->id)
            ->assertJsonPath('data.camera.name', 'Entry Camera')
            ->assertJsonCount(1, 'data.images');
    }

    public function test_anpr_image_metadata_store_creates_row(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-images', [
                'anpr_event_id' => $event->id,
                'image_type' => 'plate',
                'file_path' => 'runs/run_test/evidence/plate.jpg',
                'file_size' => 1024,
                'resolution' => '640x480',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.image_type', 'plate');

        $this->assertDatabaseHas('anpr_images', [
            'anpr_event_id' => $event->id,
            'image_type' => 'plate',
            'file_path' => 'runs/run_test/evidence/plate.jpg',
        ]);
    }

    public function test_anpr_event_log_store_and_index(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $createResponse = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-event-logs', [
                'anpr_event_id' => $event->id,
                'stage' => 'ai_event_created',
                'message' => json_encode(['job_id' => 'job-1']),
            ])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'ai_event_created');

        $logId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-event-logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin, 'api')
            ->getJson('/api/anpr-event-logs/'.$logId)
            ->assertOk()
            ->assertJsonPath('data.id', $logId);
    }

    public function test_anpr_event_store_links_vehicle_with_dash_separator_plate(): void
    {
        $this->assertLegacySeparatorLink('ABC-1001', 'abc-1001', 'ABC1001');
    }

    public function test_anpr_event_store_links_vehicle_with_space_separator_plate(): void
    {
        $this->assertLegacySeparatorLink('ABC 1001', 'ABC1001', 'ABC1001');
    }

    public function test_anpr_event_store_links_vehicle_with_dot_separator_plate(): void
    {
        $this->assertLegacySeparatorLink('ABC.1001', 'abc.1001', 'ABC1001');
    }

    public function test_anpr_event_store_links_vehicle_with_underscore_separator_plate(): void
    {
        $this->assertLegacySeparatorLink('ABC_1001', 'ABC-1001', 'ABC1001');
    }

    public function test_anpr_event_store_links_vehicle_with_slash_separator_plate(): void
    {
        $this->assertLegacySeparatorLink('ABC/1001', 'ABC1001', 'ABC1001');
    }

    protected function assertLegacySeparatorLink(
        string $storedPlate,
        string $incomingPlate,
        string $expectedNormalized,
    ): void {
        $admin = $this->adminUser();
        $existing = Vehicle::factory()->create([
            'plate_number' => $storedPlate,
            'source' => 'manual',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->aiCompatiblePayload($incomingPlate))
            ->assertCreated()
            ->assertJsonPath('data.vehicle_id', $existing->id)
            ->assertJsonPath('data.plate_number', $expectedNormalized);

        $this->assertDatabaseCount('vehicles', 1);
    }
}

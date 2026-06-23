<?php

namespace Tests\Feature;

use App\Models\AnprEvent;
use App\Models\Camera;
use App\Models\Vehicle;
use App\Services\Anpr\AnprVehicleLinker;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AnprVehicleLinkingTest extends TestCase
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
    protected function anprEventPayload(string $plateNumber, ?string $cameraId = null): array
    {
        return [
            'camera_id' => $cameraId ?? Camera::factory()->create()->id,
            'plate_number' => $plateNumber,
            'confidence' => 0.92,
            'detection_time' => now()->toIso8601String(),
            'is_valid' => true,
        ];
    }

    public function test_anpr_event_store_reuses_existing_vehicle_for_normalized_plate(): void
    {
        $admin = $this->adminUser();
        $existing = Vehicle::factory()->create([
            'plate_number' => 'ABC1001',
            'owner_name' => 'Jane Owner',
            'vehicle_type' => 'car',
            'status' => 'normal',
            'source' => 'manual',
            'notes' => 'Keep this note',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('abc-1001'))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.vehicle_id', $existing->id)
            ->assertJsonPath('data.plate_number', 'ABC1001')
            ->assertJsonPath('data.vehicle.owner_name', 'Jane Owner');

        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseHas('anpr_events', [
            'id' => $response->json('data.id'),
            'vehicle_id' => $existing->id,
            'plate_number' => 'ABC1001',
        ]);
    }

    public function test_anpr_event_store_auto_creates_vehicle_for_unknown_plate(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('xyz-7788'))
            ->assertCreated()
            ->assertJsonPath('data.plate_number', 'XYZ7788')
            ->assertJsonPath('data.vehicle.plate_number', 'XYZ7788')
            ->assertJsonPath('data.vehicle.source', 'auto_detected')
            ->assertJsonPath('data.vehicle.status', 'normal')
            ->assertJsonPath('data.vehicle.owner_name', null)
            ->assertJsonPath('data.vehicle.vehicle_type', null)
            ->assertJsonPath('data.vehicle.notes', null);

        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseHas('vehicles', [
            'plate_number' => 'XYZ7788',
            'source' => 'auto_detected',
            'status' => 'normal',
            'owner_name' => null,
            'vehicle_type' => null,
        ]);
    }

    public function test_reposting_same_plate_does_not_create_duplicate_vehicle(): void
    {
        $admin = $this->adminUser();
        $payload = $this->anprEventPayload('MYS-1234');

        $this->actingAs($admin, 'api')->postJson('/api/anpr-events', $payload)->assertCreated();
        $this->actingAs($admin, 'api')->postJson('/api/anpr-events', $payload)->assertCreated();

        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'MYS1234']);
        $this->assertSame(2, AnprEvent::query()->count());
    }

    public function test_existing_vehicle_metadata_is_preserved_on_relink(): void
    {
        $admin = $this->adminUser();
        $existing = Vehicle::factory()->create([
            'plate_number' => 'PRESERVE1',
            'owner_name' => 'Original Owner',
            'vehicle_type' => 'van',
            'status' => 'whitelist',
            'source' => 'manual',
            'notes' => 'Do not overwrite',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('preserve-1'))
            ->assertCreated()
            ->assertJsonPath('data.vehicle.id', $existing->id)
            ->assertJsonPath('data.vehicle.owner_name', 'Original Owner')
            ->assertJsonPath('data.vehicle.vehicle_type', 'van')
            ->assertJsonPath('data.vehicle.status', 'whitelist')
            ->assertJsonPath('data.vehicle.source', 'manual')
            ->assertJsonPath('data.vehicle.notes', 'Do not overwrite');

        $existing->refresh();
        $this->assertSame('Original Owner', $existing->owner_name);
        $this->assertSame('manual', $existing->source);
    }

    public function test_flagged_vehicle_makes_created_anpr_event_flagged(): void
    {
        $admin = $this->adminUser();
        Vehicle::factory()->create([
            'plate_number' => 'FLAG900',
            'status' => 'flagged',
            'source' => 'manual',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', array_merge($this->anprEventPayload('flag-900'), ['is_flagged' => false]))
            ->assertCreated()
            ->assertJsonPath('data.is_flagged', true)
            ->assertJsonPath('data.vehicle.status', 'flagged');
    }

    public function test_whitelist_vehicle_is_linked_and_exposed_in_event_resource(): void
    {
        $admin = $this->adminUser();
        Vehicle::factory()->create([
            'plate_number' => 'WHITE500',
            'status' => 'whitelist',
            'source' => 'manual',
            'owner_name' => 'Trusted Owner',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('white-500'))
            ->assertCreated()
            ->assertJsonPath('data.is_flagged', false)
            ->assertJsonPath('data.vehicle.status', 'whitelist')
            ->assertJsonPath('data.vehicle.owner_name', 'Trusted Owner');
    }

    public function test_anpr_event_store_response_includes_vehicle_relation(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('NEW-PLATE'))
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'vehicle_id',
                    'vehicle' => [
                        'id',
                        'plate_number',
                        'owner_name',
                        'vehicle_type',
                        'status',
                        'source',
                        'notes',
                    ],
                ],
            ]);
    }

    public function test_vehicle_endpoints_are_admin_only(): void
    {
        $vehicle = Vehicle::factory()->create();
        $operator = $this->securityOperatorUser();
        $guard = $this->guardUser();

        $this->getJson('/api/vehicles')->assertUnauthorized();

        $this->actingAs($operator, 'api')->getJson('/api/vehicles')->assertForbidden();
        $this->actingAs($guard, 'api')->getJson('/api/vehicles')->assertForbidden();
        $this->actingAs($operator, 'api')->getJson('/api/vehicles/'.$vehicle->id)->assertForbidden();
        $this->actingAs($operator, 'api')->patchJson('/api/vehicles/'.$vehicle->id, [
            'owner_name' => 'Blocked',
        ])->assertForbidden();

        $this->actingAs($this->adminUser(), 'api')->getJson('/api/vehicles')->assertOk();
    }

    public function test_vehicle_update_allows_owner_type_status_and_notes(): void
    {
        $admin = $this->adminUser();
        $vehicle = Vehicle::factory()->create([
            'owner_name' => null,
            'vehicle_type' => null,
            'status' => 'normal',
            'notes' => null,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/vehicles/'.$vehicle->id, [
                'owner_name' => 'Updated Owner',
                'vehicle_type' => 'truck',
                'status' => 'flagged',
                'notes' => 'Watchlist note',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner_name', 'Updated Owner')
            ->assertJsonPath('data.vehicle_type', 'truck')
            ->assertJsonPath('data.status', 'flagged')
            ->assertJsonPath('data.notes', 'Watchlist note');
    }

    public function test_vehicle_update_rejects_plate_number_changes(): void
    {
        $admin = $this->adminUser();
        $vehicle = Vehicle::factory()->create(['plate_number' => 'LOCKED1']);

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/vehicles/'.$vehicle->id, [
                'plate_number' => 'CHANGED1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('plate_number', $response->json('data.errors'));
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'plate_number' => 'LOCKED1']);
    }

    public function test_vehicle_update_rejects_source_changes(): void
    {
        $admin = $this->adminUser();
        $vehicle = Vehicle::factory()->create(['source' => 'manual']);

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/vehicles/'.$vehicle->id, [
                'source' => 'auto_detected',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('source', $response->json('data.errors'));
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'source' => 'manual']);
    }

    public function test_manual_vehicle_create_stores_normalized_plate(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'api')
            ->postJson('/api/vehicles', ['plate_number' => 'abc-1001'])
            ->assertCreated()
            ->assertJsonPath('data.plate_number', 'ABC1001')
            ->assertJsonPath('data.source', 'manual');

        $this->assertDatabaseHas('vehicles', [
            'plate_number' => 'ABC1001',
            'source' => 'manual',
        ]);
    }

    public function test_manual_vehicle_create_rejects_normalized_duplicate_against_canonical_plate(): void
    {
        $admin = $this->adminUser();
        Vehicle::factory()->create(['plate_number' => 'ABC1001', 'source' => 'manual']);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/vehicles', ['plate_number' => 'ABC-1001'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('plate_number', $response->json('data.errors'));
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_manual_vehicle_create_rejects_normalized_duplicate_against_legacy_separator_plate(): void
    {
        $admin = $this->adminUser();
        Vehicle::factory()->create(['plate_number' => 'ABC_1001', 'source' => 'manual']);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/vehicles', ['plate_number' => 'ABC1001'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('plate_number', $response->json('data.errors'));
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_anpr_event_store_rejects_empty_normalized_plate(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('---'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            ['Plate number cannot be empty after normalization.'],
            $response->json('data.errors.plate_number')
        );
    }

    public function test_anpr_event_update_rejects_vehicle_id(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();
        $otherVehicle = Vehicle::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-events/'.$event->id, [
                'vehicle_id' => $otherVehicle->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('vehicle_id', $response->json('data.errors'));
    }

    public function test_anpr_event_update_relinks_when_plate_changes(): void
    {
        $admin = $this->adminUser();
        $camera = Camera::factory()->create();
        $originalVehicle = Vehicle::factory()->create(['plate_number' => 'ABC1001', 'source' => 'manual']);

        $event = AnprEvent::factory()->create([
            'camera_id' => $camera->id,
            'vehicle_id' => $originalVehicle->id,
            'plate_number' => 'ABC1001',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-events/'.$event->id, [
                'plate_number' => 'xyz-2002',
            ])
            ->assertOk()
            ->assertJsonPath('data.plate_number', 'XYZ2002')
            ->assertJsonPath('data.vehicle.plate_number', 'XYZ2002');

        $this->assertNotSame($originalVehicle->id, $response->json('data.vehicle_id'));
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'XYZ2002']);
    }

    public function test_anpr_event_update_derives_flagged_status_from_linked_vehicle(): void
    {
        $admin = $this->adminUser();
        $camera = Camera::factory()->create();
        $originalVehicle = Vehicle::factory()->create([
            'plate_number' => 'ABC1001',
            'status' => 'normal',
            'source' => 'manual',
        ]);
        $flaggedVehicle = Vehicle::factory()->create([
            'plate_number' => 'XYZ2002',
            'status' => 'flagged',
            'source' => 'manual',
        ]);

        $event = AnprEvent::factory()->create([
            'camera_id' => $camera->id,
            'vehicle_id' => $originalVehicle->id,
            'plate_number' => 'ABC1001',
            'is_flagged' => false,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-events/'.$event->id, [
                'plate_number' => 'xyz-2002',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_flagged', true)
            ->assertJsonPath('data.vehicle_id', $flaggedVehicle->id)
            ->assertJsonPath('data.vehicle.status', 'flagged');
    }

    public function test_anpr_event_update_rejects_empty_normalized_plate(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-events/'.$event->id, [
                'plate_number' => '---',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            ['Plate number cannot be empty after normalization.'],
            $response->json('data.errors.plate_number')
        );
    }

    public function test_normalized_plate_column_sql_uses_mysql_safe_backslash_removal(): void
    {
        $linker = app(AnprVehicleLinker::class);
        $sql = $linker->normalizedPlateColumnSql('plate_number');

        $this->assertStringContainsString('CHAR(92)', $sql);
        $this->assertStringNotContainsString("REPLACE(..., '\\', '')", $sql);
        $this->assertStringNotContainsString("'), '\\', '')", $sql);
    }

    public function test_anpr_event_store_reuses_vehicle_with_legacy_backslash_plate(): void
    {
        $admin = $this->adminUser();
        $existing = Vehicle::factory()->create([
            'plate_number' => 'ABC\\1001',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/anpr-events', $this->anprEventPayload('ABC1001'))
            ->assertCreated()
            ->assertJsonPath('data.vehicle_id', $existing->id)
            ->assertJsonPath('data.plate_number', 'ABC1001');

        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseHas('anpr_events', [
            'id' => $response->json('data.id'),
            'vehicle_id' => $existing->id,
        ]);
    }

    public function test_manual_vehicle_create_rejects_normalized_duplicate_against_backslash_plate(): void
    {
        $admin = $this->adminUser();
        Vehicle::factory()->create(['plate_number' => 'ABC\\1001', 'source' => 'manual']);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/vehicles', ['plate_number' => 'ABC1001'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('plate_number', $response->json('data.errors'));
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_anpr_event_update_rejects_is_flagged(): void
    {
        $admin = $this->adminUser();
        $event = AnprEvent::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/anpr-events/'.$event->id, [
                'is_flagged' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('is_flagged', $response->json('data.errors'));
    }
}

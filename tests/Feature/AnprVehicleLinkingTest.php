<?php

namespace Tests\Feature;

use App\Models\AnprEvent;
use App\Models\Camera;
use App\Models\Vehicle;
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
}

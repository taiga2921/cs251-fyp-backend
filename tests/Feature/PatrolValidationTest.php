<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\CheckpointEvent;
use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use App\Services\PatrolValidationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatrolValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_validate_patrol_session_via_service_detects_continuous_checkpoint(): void
    {
        $user = User::factory()->create();
        $zone = Zone::factory()->create();
        $checkpoint = Checkpoint::factory()->create([
            'zone_id' => $zone->id,
            'latitude' => 3.139,
            'longitude' => 101.6869,
            'radius' => 30,
        ]);
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'zone_id' => $zone->id,
            'status' => 'active',
        ]);

        $baseTs = now()->getTimestampMs();

        foreach ([0, 2000, 4000, 6000] as $offsetMs) {
            LocationLog::query()->create([
                'id' => (string) Str::uuid(),
                'patrol_session_id' => $patrol->id,
                'user_id' => $user->id,
                'latitude' => $checkpoint->latitude,
                'longitude' => $checkpoint->longitude,
                'accuracy' => 10,
                'timestamp' => $baseTs + $offsetMs,
                'server_received_at' => now(),
                'source' => 'live',
                'tracking_state' => 'active',
            ]);
        }

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);

        $this->assertSame(4, $result['total_location_logs']);
        $this->assertSame('verified', $result['checkpoint_results'][0]['status']);
        $this->assertSame('continuous', $result['checkpoint_results'][0]['detection_type']);

        $event = CheckpointEvent::query()
            ->where('patrol_session_id', $patrol->id)
            ->where('checkpoint_id', $checkpoint->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('continuous', $event->detection_type);
    }

    public function test_validate_endpoint_returns_json_envelope(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/patrol-sessions/'.$patrol->id.'/validate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Patrol session validation completed.');
    }

    public function test_validate_route_requires_authentication(): void
    {
        $patrol = PatrolSession::factory()->create();

        $this->postJson('/api/patrol-sessions/'.$patrol->id.'/validate')
            ->assertUnauthorized();
    }
}

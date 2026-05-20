<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\CheckpointEvent;
use App\Models\CheckpointEventMetric;
use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use App\Services\PatrolValidationService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPatrolFixtures;
use Tests\TestCase;

class PatrolValidationTest extends TestCase
{
    use CreatesPatrolFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_continuous_checkpoint_stay_at_least_three_seconds_becomes_verified(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 2000],
            ['offset_ms' => 4000],
            ['offset_ms' => 6000],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);

        $this->assertSame('verified', $result['checkpoint_results'][0]['status']);
        $this->assertSame('continuous', $result['checkpoint_results'][0]['detection_type']);
        $this->assertGreaterThanOrEqual(80, $result['checkpoint_results'][0]['confidence_score']);
    }

    public function test_short_stay_below_three_seconds_is_rejected_or_uncertain(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 1500],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);
        $status = $result['checkpoint_results'][0]['status'];

        $this->assertContains($status, ['rejected', 'uncertain']);
        $this->assertLessThan(80, $result['checkpoint_results'][0]['confidence_score']);
    }

    public function test_resume_detection_inside_radius_is_uncertain_with_capped_confidence(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            [
                'offset_ms' => 0,
                'source' => 'resume',
                'tracking_state' => 'resumed',
            ],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);

        $this->assertSame('resume', $result['checkpoint_results'][0]['detection_type']);
        $this->assertSame('uncertain', $result['checkpoint_results'][0]['status']);
        $this->assertLessThanOrEqual(79, $result['checkpoint_results'][0]['confidence_score']);
    }

    public function test_large_gap_reduces_gap_factor_in_checkpoint_results(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 2000],
            ['offset_ms' => 4000],
            ['offset_ms' => 50000],
            ['offset_ms' => 52000],
            ['offset_ms' => 54000],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);

        $this->assertLessThan(1.0, $result['checkpoint_results'][0]['gap_factor']);
    }

    public function test_gps_jump_creates_anomaly_item(): void
    {
        $user = User::factory()->create();
        $zone = Zone::factory()->create();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'zone_id' => $zone->id,
        ]);
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        LocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'patrol_session_id' => $patrol->id,
            'user_id' => $user->id,
            'latitude' => 3.139,
            'longitude' => 101.6869,
            'accuracy' => 10,
            'timestamp' => $baseTs,
            'server_received_at' => now(),
            'source' => 'live',
            'tracking_state' => 'active',
        ]);

        LocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'patrol_session_id' => $patrol->id,
            'user_id' => $user->id,
            'latitude' => 3.141,
            'longitude' => 101.6969,
            'accuracy' => 10,
            'timestamp' => $baseTs + 3000,
            'server_received_at' => now(),
            'source' => 'live',
            'tracking_state' => 'active',
        ]);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);
        $jumpItems = array_filter(
            $result['anomalies']['items'] ?? [],
            fn (array $item) => $item['type'] === 'gps_jump'
        );

        $this->assertNotEmpty($jumpItems);
    }

    public function test_poor_accuracy_creates_anomaly_item(): void
    {
        ['user' => $user, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0, 'accuracy' => 55],
            ['offset_ms' => 2000, 'accuracy' => 55],
        ]);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);
        $items = array_filter(
            $result['anomalies']['items'] ?? [],
            fn (array $item) => $item['type'] === 'poor_accuracy'
        );

        $this->assertNotEmpty($items);
    }

    public function test_validation_upserts_checkpoint_event_metrics(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 2000],
            ['offset_ms' => 4000],
            ['offset_ms' => 6000],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $service = app(PatrolValidationService::class);
        $service->validatePatrolSession($patrol);

        $this->assertSame(1, CheckpointEvent::query()->where('patrol_session_id', $patrol->id)->count());
        $this->assertSame(1, CheckpointEventMetric::query()->count());

        $service->validatePatrolSession($patrol);

        $this->assertSame(1, CheckpointEvent::query()->where('patrol_session_id', $patrol->id)->count());
        $this->assertSame(1, CheckpointEventMetric::query()->count());
    }

    public function test_re_running_validation_updates_existing_event_instead_of_duplicating(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 2000],
            ['offset_ms' => 4000],
            ['offset_ms' => 6000],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $service = app(PatrolValidationService::class);
        $service->validatePatrolSession($patrol);
        $eventId = CheckpointEvent::query()
            ->where('patrol_session_id', $patrol->id)
            ->value('id');

        $service->validatePatrolSession($patrol);

        $this->assertSame(
            $eventId,
            CheckpointEvent::query()->where('patrol_session_id', $patrol->id)->value('id')
        );
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

    public function test_validate_returns_flat_anomaly_items_for_speed_anomaly(): void
    {
        $user = User::factory()->create();
        $zone = Zone::factory()->create();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'zone_id' => $zone->id,
        ]);

        $baseTs = now()->getTimestampMs();

        LocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'patrol_session_id' => $patrol->id,
            'user_id' => $user->id,
            'latitude' => 3.139,
            'longitude' => 101.6869,
            'accuracy' => 10,
            'timestamp' => $baseTs,
            'server_received_at' => now(),
            'source' => 'live',
            'tracking_state' => 'active',
        ]);

        LocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'patrol_session_id' => $patrol->id,
            'user_id' => $user->id,
            'latitude' => 3.149,
            'longitude' => 101.6969,
            'accuracy' => 10,
            'timestamp' => $baseTs + 2000,
            'server_received_at' => now(),
            'source' => 'live',
            'tracking_state' => 'active',
        ]);

        $result = app(PatrolValidationService::class)->validatePatrolSession($patrol);

        $items = $result['anomalies']['items'] ?? [];
        $speedItems = array_values(array_filter($items, fn (array $item) => $item['type'] === 'speed_anomaly'));

        $this->assertNotEmpty($speedItems);
        $this->assertSame('major', $speedItems[0]['severity']);
        $this->assertArrayHasKey('start_log_id', $speedItems[0]);
        $this->assertArrayHasKey('end_latitude', $speedItems[0]);
        $this->assertArrayHasKey('speed_mps', $speedItems[0]);
    }
}

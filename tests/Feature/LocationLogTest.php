<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPatrolFixtures;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class LocationLogTest extends TestCase
{
    use CreatesPatrolFixtures;
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_guard_cannot_delete_location_log(): void
    {
        $log = $this->createLocationLog();

        $this->actingAs($this->guardUser(), 'api')
            ->deleteJson('/api/location-logs/'.$log->id)
            ->assertMethodNotAllowed();
    }

    public function test_security_operator_cannot_delete_location_log(): void
    {
        $log = $this->createLocationLog();

        $this->actingAs($this->securityOperatorUser(), 'api')
            ->deleteJson('/api/location-logs/'.$log->id)
            ->assertMethodNotAllowed();
    }

    public function test_admin_cannot_delete_location_log(): void
    {
        $log = $this->createLocationLog();

        $this->actingAs($this->adminUser(), 'api')
            ->deleteJson('/api/location-logs/'.$log->id)
            ->assertMethodNotAllowed();

        $this->assertNotNull(LocationLog::query()->find($log->id));
    }

    public function test_update_route_is_not_available(): void
    {
        $log = $this->createLocationLog();

        $this->actingAs($this->adminUser(), 'api')
            ->putJson('/api/location-logs/'.$log->id, ['latitude' => 1.0])
            ->assertMethodNotAllowed();

        $this->actingAs($this->adminUser(), 'api')
            ->patchJson('/api/location-logs/'.$log->id, ['latitude' => 1.0])
            ->assertMethodNotAllowed();
    }

    public function test_authenticated_user_can_create_location_log(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        ['user' => $user, 'patrol' => $patrol] = $this->patrolValidationContext();
        $logId = (string) Str::uuid();

        $this->actingAs($user, 'api')
            ->postJson('/api/location-logs', [
                'id' => $logId,
                'patrol_session_id' => $patrol->id,
                'user_id' => $user->id,
                'latitude' => 3.139,
                'longitude' => 101.6869,
                'accuracy' => 12,
                'timestamp' => Carbon::now()->getTimestampMs(),
                'source' => 'live',
                'tracking_state' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $logId);

        $this->assertNotNull(LocationLog::query()->find($logId)?->server_received_at);
        Carbon::setTestNow();
    }

    public function test_pwa_sync_inserts_location_log_after_hardening(): void
    {
        ['user' => $user, 'patrol' => $patrol] = $this->patrolValidationContext();
        $locationLogId = (string) Str::uuid();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', [
                'type' => 'location_log',
                'locationLogId' => $locationLogId,
                'patrolId' => $patrol->id,
                'userId' => $user->id,
                'timestamp' => Carbon::parse('2026-05-20 10:00:00')->getTimestampMs(),
                'lat' => 3.139,
                'lng' => 101.6869,
                'accuracy' => 10,
                'source' => 'live',
                'trackingState' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, LocationLog::query()->whereKey($locationLogId)->count());
    }

    public function test_validation_still_works_after_hardening(): void
    {
        ['user' => $user, 'checkpoint' => $checkpoint, 'patrol' => $patrol] = $this->patrolValidationContext();
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        $this->seedLocationLogs($patrol, $user, $baseTs, [
            ['offset_ms' => 0],
            ['offset_ms' => 2000],
            ['offset_ms' => 4000],
            ['offset_ms' => 6000],
        ], (float) $checkpoint->latitude, (float) $checkpoint->longitude);

        $this->actingAs($this->adminUser(), 'api')
            ->postJson('/api/patrol-sessions/'.$patrol->id.'/validate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checkpoint_results.0.status', 'verified');
    }

    protected function createLocationLog(): LocationLog
    {
        ['user' => $user, 'patrol' => $patrol] = $this->patrolValidationContext();

        return LocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'patrol_session_id' => $patrol->id,
            'user_id' => $user->id,
            'latitude' => 3.139,
            'longitude' => 101.6869,
            'accuracy' => 10,
            'timestamp' => Carbon::parse('2026-05-20 10:00:00')->getTimestampMs(),
            'server_received_at' => now(),
            'source' => 'live',
            'tracking_state' => 'active',
        ]);
    }
}

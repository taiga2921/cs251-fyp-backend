<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PwaSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_user_can_sync_valid_location_log(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        [$user, $patrol] = $this->createPatrolContext();
        $locationLogId = (string) Str::uuid();
        $payload = $this->validPayload($locationLogId, $user, $patrol);

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.duplicate', false);

        $log = LocationLog::query()->findOrFail($locationLogId);
        $this->assertSame($patrol->id, $log->patrol_session_id);
        $this->assertNotNull($log->server_received_at);
        Carbon::setTestNow();
    }

    public function test_unauthenticated_sync_is_rejected(): void
    {
        [$user, $patrol] = $this->createPatrolContext();

        $this->postJson('/api/pwa/sync', $this->validPayload((string) Str::uuid(), $user, $patrol))
            ->assertUnauthorized();
    }

    public function test_invalid_patrol_id_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', [
                'type' => 'location_log',
                'locationLogId' => (string) Str::uuid(),
                'patrolId' => (string) Str::uuid(),
                'userId' => $user->id,
                'timestamp' => now()->getTimestampMs(),
                'lat' => 3.139,
                'lng' => 101.6869,
                'source' => 'live',
                'trackingState' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_manual_source_is_stored_as_sync(): void
    {
        [$user, $patrol] = $this->createPatrolContext();
        $locationLogId = (string) Str::uuid();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', array_merge(
                $this->validPayload($locationLogId, $user, $patrol),
                ['source' => 'manual']
            ))
            ->assertCreated();

        $this->assertSame('sync', LocationLog::query()->findOrFail($locationLogId)->source);
    }

    public function test_duplicate_replay_returns_success_with_duplicate_flag(): void
    {
        [$user, $patrol] = $this->createPatrolContext();
        $locationLogId = (string) Str::uuid();

        $payload = $this->validPayload($locationLogId, $user, $patrol);

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', $payload)
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', $payload)
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.duplicate', true);
    }

    public function test_mismatched_payload_for_same_id_returns_conflict(): void
    {
        [$user, $patrol] = $this->createPatrolContext();
        $locationLogId = (string) Str::uuid();

        $payload = $this->validPayload($locationLogId, $user, $patrol);

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', $payload)
            ->assertCreated();

        $conflictPayload = array_merge($payload, ['lat' => $payload['lat'] + 0.0001]);

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', $conflictPayload)
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertSame(1, LocationLog::query()->whereKey($locationLogId)->count());
    }

    public function test_sync_bumps_duplicate_device_timestamp_for_same_patrol_session(): void
    {
        [$user, $patrol] = $this->createPatrolContext();
        $sharedTimestamp = now()->getTimestampMs();
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', array_merge(
                $this->validPayload($firstId, $user, $patrol),
                ['timestamp' => $sharedTimestamp],
            ))
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', array_merge(
                $this->validPayload($secondId, $user, $patrol),
                ['timestamp' => $sharedTimestamp],
            ))
            ->assertCreated();

        $first = LocationLog::query()->findOrFail($firstId);
        $second = LocationLog::query()->findOrFail($secondId);

        $this->assertSame($sharedTimestamp, (int) $first->timestamp);
        $this->assertSame($sharedTimestamp + 1, (int) $second->timestamp);
    }

    public function test_validation_error_returns_422(): void
    {
        [$user, $patrol] = $this->createPatrolContext();

        $this->actingAs($user, 'api')
            ->postJson('/api/pwa/sync', [
                'type' => 'location_log',
                'locationLogId' => (string) Str::uuid(),
                'patrolId' => $patrol->id,
                'userId' => $user->id,
                'timestamp' => now()->getTimestampMs(),
                'lat' => 999,
                'lng' => 101.6869,
                'source' => 'live',
                'trackingState' => 'active',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
            ]);
    }

    /**
     * @return array{0: User, 1: PatrolSession}
     */
    protected function createPatrolContext(): array
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $patrol];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(string $locationLogId, User $user, PatrolSession $patrol): array
    {
        return [
            'type' => 'location_log',
            'locationLogId' => $locationLogId,
            'patrolId' => $patrol->id,
            'userId' => $user->id,
            'timestamp' => now()->getTimestampMs(),
            'lat' => 3.139,
            'lng' => 101.6869,
            'accuracy' => 12.5,
            'source' => 'live',
            'trackingState' => 'active',
            'speed' => null,
            'heading' => null,
        ];
    }
}

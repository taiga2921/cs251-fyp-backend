<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
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

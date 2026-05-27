<?php

namespace Tests\Concerns;

use App\Models\Checkpoint;
use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

trait CreatesPatrolFixtures
{
    /**
     * @return array{user: User, zone: Zone, checkpoint: Checkpoint, patrol: PatrolSession}
     */
    protected function patrolValidationContext(): array
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

        return compact('user', 'zone', 'checkpoint', 'patrol');
    }

    /**
     * @param  list<array{offset_ms: int, lat?: float, lng?: float, accuracy?: float, source?: string, tracking_state?: string}>  $points
     */
    protected function seedLocationLogs(
        PatrolSession $patrol,
        User $user,
        int $baseTs,
        array $points,
        float $defaultLat = 3.139,
        float $defaultLng = 101.6869,
    ): void {
        foreach ($points as $point) {
            LocationLog::query()->create([
                'id' => (string) Str::uuid(),
                'patrol_session_id' => $patrol->id,
                'user_id' => $user->id,
                'latitude' => $point['lat'] ?? $defaultLat,
                'longitude' => $point['lng'] ?? $defaultLng,
                'accuracy' => $point['accuracy'] ?? 10,
                'timestamp' => $baseTs + $point['offset_ms'],
                'server_received_at' => now(),
                'source' => $point['source'] ?? 'live',
                'tracking_state' => $point['tracking_state'] ?? 'active',
            ]);
        }
    }
}

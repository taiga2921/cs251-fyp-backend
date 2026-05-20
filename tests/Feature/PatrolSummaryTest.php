<?php

namespace Tests\Feature;

use App\Models\CheckpointEvent;
use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use App\Services\PatrolSessionSummaryService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatrolSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_summary_returns_checkpoint_counts_and_completion_percentage(): void
    {
        $user = User::factory()->create();
        $zone = Zone::factory()->create();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'zone_id' => $zone->id,
            'status' => 'completed',
        ]);

        CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'verified',
        ]);
        CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'rejected',
        ]);

        $summary = app(PatrolSessionSummaryService::class)->build($patrol);

        $this->assertSame(2, $summary['total_checkpoints']);
        $this->assertSame(1, $summary['verified_checkpoints']);
        $this->assertSame(50.0, $summary['completion_percentage']);
    }

    public function test_summary_detects_gaps_over_thirty_seconds(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);
        $baseTs = Carbon::parse('2026-05-20 10:00:00')->getTimestampMs();

        foreach ([0, 5000, 45000] as $offsetMs) {
            LocationLog::query()->create([
                'id' => (string) Str::uuid(),
                'patrol_session_id' => $patrol->id,
                'user_id' => $user->id,
                'latitude' => 3.139,
                'longitude' => 101.6869,
                'accuracy' => 10,
                'timestamp' => $baseTs + $offsetMs,
                'server_received_at' => now(),
                'source' => 'live',
                'tracking_state' => 'active',
            ]);
        }

        $summary = app(PatrolSessionSummaryService::class)->build($patrol);

        $this->assertSame(1, $summary['total_gaps']);
        $this->assertGreaterThan(30, $summary['longest_gap_seconds']);
    }

    public function test_summary_reduces_confidence_for_pending_rejected_and_suspicious(): void
    {
        $patrol = PatrolSession::factory()->create();

        CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'pending',
        ]);
        CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'rejected',
        ]);
        CheckpointEvent::factory()->create([
            'patrol_session_id' => $patrol->id,
            'status' => 'suspicious',
        ]);

        $summary = app(PatrolSessionSummaryService::class)->build($patrol);

        $this->assertSame(65, $summary['confidence_score']);
        $this->assertSame('medium', $summary['confidence_level']);
    }

    public function test_summary_works_when_no_location_logs_exist(): void
    {
        $patrol = PatrolSession::factory()->create();

        $summary = app(PatrolSessionSummaryService::class)->build($patrol);

        $this->assertSame(0, $summary['total_location_logs']);
        $this->assertSame(0, $summary['total_gaps']);
        $this->assertSame(0, $summary['longest_gap_seconds']);
    }

    public function test_summary_endpoint_requires_authentication(): void
    {
        $patrol = PatrolSession::factory()->create();

        $this->getJson('/api/patrol-sessions/'.$patrol->id.'/summary')
            ->assertUnauthorized();
    }

    public function test_summary_endpoint_returns_json_envelope(): void
    {
        $user = User::factory()->create();
        $patrol = PatrolSession::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->getJson('/api/patrol-sessions/'.$patrol->id.'/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'patrol_session_id',
                    'total_checkpoints',
                    'verified_checkpoints',
                    'completion_percentage',
                    'confidence_score',
                ],
            ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CheckpointEvent;
use App\Models\CheckpointEventMetric;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckpointEventMetricTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_metric_can_be_created_for_checkpoint_event(): void
    {
        $user = User::factory()->create();
        $event = CheckpointEvent::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/checkpoint-event-metrics', [
                'checkpoint_event_id' => $event->id,
                'distance_score' => 80,
                'accuracy_score' => 75,
                'time_score' => 90,
                'stability_score' => 70,
                'gap_factor' => 1,
                'integrity_factor' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checkpoint_event_id', $event->id);
    }

    public function test_cannot_create_duplicate_metric_for_same_checkpoint_event(): void
    {
        $user = User::factory()->create();
        $event = CheckpointEvent::factory()->create();
        $payload = [
            'checkpoint_event_id' => $event->id,
            'distance_score' => 80,
            'accuracy_score' => 75,
            'time_score' => 90,
            'stability_score' => 70,
            'gap_factor' => 1,
            'integrity_factor' => 1,
        ];

        $this->actingAs($user, 'api')->postJson('/api/checkpoint-event-metrics', $payload)->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/checkpoint-event-metrics', $payload)
            ->assertStatus(422);
    }

    public function test_calculated_confidence_score_is_returned_in_resource(): void
    {
        $user = User::factory()->create();
        $event = CheckpointEvent::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/checkpoint-event-metrics', [
                'checkpoint_event_id' => $event->id,
                'distance_score' => 100,
                'accuracy_score' => 100,
                'time_score' => 100,
                'stability_score' => 100,
                'gap_factor' => 1,
                'integrity_factor' => 1,
            ])
            ->assertCreated();

        $this->assertEquals(100.0, (float) $response->json('data.calculated_confidence_score'));
    }

    public function test_metrics_are_deleted_when_checkpoint_event_is_deleted(): void
    {
        $user = User::factory()->create();
        $event = CheckpointEvent::factory()->create();
        $metric = CheckpointEventMetric::factory()->create([
            'checkpoint_event_id' => $event->id,
        ]);

        $event->delete();

        $this->assertNull(CheckpointEventMetric::query()->find($metric->id));
    }
}

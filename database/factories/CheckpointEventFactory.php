<?php

namespace Database\Factories;

use App\Models\Checkpoint;
use App\Models\CheckpointEvent;
use App\Models\PatrolSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckpointEvent>
 */
class CheckpointEventFactory extends Factory
{
    protected $model = CheckpointEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $patrolSessionId = PatrolSession::query()->inRandomOrder()->value('id')
            ?? PatrolSession::factory()->create()->id;
        $checkpointId = Checkpoint::query()->inRandomOrder()->value('id')
            ?? Checkpoint::factory()->create()->id;

        $enteredAt = ->faker->boolean(60) ? ->faker->dateTimeBetween('-7 days', 'now') : null;
        $exitedAt = null;
        if ($enteredAt !== null && ->faker->boolean(70)) {
            $exitedAt = ->faker->dateTimeBetween($enteredAt, 'now');
        }

        $detectedAt = ->faker->boolean(85) ? ->faker->dateTimeBetween('-7 days', 'now') : null;
        $processedAt = ->faker->boolean(75) ? ->faker->dateTimeBetween($detectedAt ?? '-7 days', 'now') : null;

        return [
            'patrol_session_id' => $patrolSessionId,
            'checkpoint_id' => $checkpointId,
            'entered_at' => $enteredAt,
            'exited_at' => $exitedAt,
            'detected_at' => $detectedAt,
            'processed_at' => $processedAt,
            'detection_type' => ->faker->randomElement(['continuous', 'resume']),
            'confidence_score' => ->faker->randomFloat(2, 0, 100),
            'status' => ->faker->randomElement(['pending', 'verified', 'suspicious', 'uncertain', 'rejected']),
        ];
    }
}

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

        $enteredAt = $this->faker->boolean(60) ? $this->faker->dateTimeBetween('-7 days', 'now') : null;
        $exitedAt = null;
        if ($enteredAt !== null && $this->faker->boolean(70)) {
            $exitedAt = $this->faker->dateTimeBetween($enteredAt, 'now');
        }

        $detectedAt = $this->faker->boolean(85) ? $this->faker->dateTimeBetween('-7 days', 'now') : null;
        $processedAt = $this->faker->boolean(75) ? $this->faker->dateTimeBetween($detectedAt ?? '-7 days', 'now') : null;

        return [
            'patrol_session_id' => $patrolSessionId,
            'checkpoint_id' => $checkpointId,
            'entered_at' => $enteredAt,
            'exited_at' => $exitedAt,
            'detected_at' => $detectedAt,
            'processed_at' => $processedAt,
            'detection_type' => $this->faker->randomElement(['continuous', 'resume']),
            'confidence_score' => $this->faker->randomFloat(2, 0, 100),
            'status' => $this->faker->randomElement(['pending', 'verified', 'suspicious', 'uncertain', 'rejected']),
        ];
    }
}

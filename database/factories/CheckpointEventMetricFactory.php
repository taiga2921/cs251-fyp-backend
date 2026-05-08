<?php

namespace Database\Factories;

use App\Models\CheckpointEvent;
use App\Models\CheckpointEventMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckpointEventMetric>
 */
class CheckpointEventMetricFactory extends Factory
{
    protected $model = CheckpointEventMetric::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checkpoint_event_id' => CheckpointEvent::factory()->create()->id,
            'distance_score' => $this->faker->randomFloat(2, 0, 100),
            'accuracy_score' => $this->faker->randomFloat(2, 0, 100),
            'time_score' => $this->faker->randomFloat(2, 0, 100),
            'stability_score' => $this->faker->randomFloat(2, 0, 100),
            'gap_factor' => $this->faker->randomFloat(2, 0, 1),
            'integrity_factor' => $this->faker->randomFloat(2, 0, 1),
        ];
    }
}

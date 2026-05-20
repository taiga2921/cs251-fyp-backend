<?php

namespace Database\Factories;

use App\Models\PatrolRoute;
use App\Models\PatrolSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatrolRoute>
 */
class PatrolRouteFactory extends Factory
{
    protected $model = PatrolRoute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patrol_session_id' => PatrolSession::factory(),
            'latitude' => $this->faker->latitude(3.1, 3.2),
            'longitude' => $this->faker->longitude(101.6, 101.7),
            'accuracy' => $this->faker->randomFloat(1, 5, 25),
            'altitude' => null,
            'recorded_at' => now(),
        ];
    }
}

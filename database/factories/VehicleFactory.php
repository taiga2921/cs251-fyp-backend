<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plate_number' => strtoupper(fake()->bothify('???-####')),
            'owner_name' => fake()->boolean(85) ? fake()->name() : null,
            'vehicle_type' => fake()->boolean(85) ? fake()->randomElement(['car', 'motorcycle', 'truck', 'van']) : null,
            'status' => fake()->randomElement(['normal', 'flagged', 'whitelist']),
            'source' => fake()->randomElement(['manual', 'auto_detected']),
            'notes' => fake()->boolean(60) ? fake()->sentence() : null,
        ];
    }
}

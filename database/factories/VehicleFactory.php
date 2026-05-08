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
            'plate_number' => strtoupper($this->faker->bothify('???-####')),
            'owner_name' => $this->faker->boolean(85) ? $this->faker->name() : null,
            'vehicle_type' => $this->faker->boolean(85) ? $this->faker->randomElement(['car', 'motorcycle', 'truck', 'van']) : null,
            'status' => $this->faker->randomElement(['normal', 'flagged', 'whitelist']),
            'source' => $this->faker->randomElement(['manual', 'auto_detected']),
            'notes' => $this->faker->boolean(60) ? $this->faker->sentence() : null,
        ];
    }
}

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
            'plate_number' => strtoupper(->faker->bothify('???-####')),
            'owner_name' => ->faker->boolean(85) ? ->faker->name() : null,
            'vehicle_type' => ->faker->boolean(85) ? ->faker->randomElement(['car', 'motorcycle', 'truck', 'van']) : null,
            'status' => ->faker->randomElement(['normal', 'flagged', 'whitelist']),
            'source' => ->faker->randomElement(['manual', 'auto_detected']),
            'notes' => ->faker->boolean(60) ? ->faker->sentence() : null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Checkpoint;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkpoint>
 */
class CheckpointFactory extends Factory
{
    protected $model = Checkpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $locationType = ->faker->randomElement(['outdoor', 'indoor']);
        $zoneId = Zone::query()->inRandomOrder()->value('id') ?? Zone::factory()->create()->id;

        return [
            'zone_id' => $zoneId,
            'name' => ->faker->randomElement([
                'Main Entrance Gate',
                'Parking Lot Checkpoint',
                'Server Room Door',
                'Reception Counter',
                'Loading Dock Point',
                'Rooftop Access Door',
                'Storage Warehouse Corner',
                'Security Booth',
                'Emergency Exit Point',
                'Ground Floor Lobby',
            ]),
            'description' => ->faker->boolean(70) ? ->faker->sentence() : null,
            'latitude' => ->faker->latitude(),
            'longitude' => ->faker->longitude(),
            'radius' => $locationType === 'indoor' ? 40 : 20,
            'location_type' => $locationType,
            'is_active' => true,
        ];
    }
}

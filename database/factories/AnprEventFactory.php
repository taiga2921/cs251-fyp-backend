<?php

namespace Database\Factories;

use App\Models\AnprEvent;
use App\Models\BlockchainRecord;
use App\Models\Camera;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnprEvent>
 */
class AnprEventFactory extends Factory
{
    protected $model = AnprEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vehicleId = fake()->boolean(70)
            ? Vehicle::query()->inRandomOrder()->value('id')
            : null;

        $blockchainRecordId = fake()->boolean(50)
            ? BlockchainRecord::query()->inRandomOrder()->value('id')
            : null;

        return [
            'vehicle_id' => $vehicleId,
            'camera_id' => Camera::query()->inRandomOrder()->value('id') ?? Camera::factory()->create()->id,
            'blockchain_record_id' => $blockchainRecordId,
            'plate_number' => strtoupper(fake()->bothify('???-####')),
            'confidence' => fake()->randomFloat(4, 0, 1),
            'detection_time' => fake()->dateTimeBetween('-14 days', 'now'),
            'is_flagged' => fake()->boolean(25),
            'is_valid' => fake()->boolean(90),
            'latitude' => fake()->boolean(85) ? fake()->latitude(-90, 90) : null,
            'longitude' => fake()->boolean(85) ? fake()->longitude(-180, 180) : null,
        ];
    }
}

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
        $vehicleId = ->faker->boolean(70)
            ? Vehicle::query()->inRandomOrder()->value('id')
            : null;

        $blockchainRecordId = ->faker->boolean(50)
            ? BlockchainRecord::query()->inRandomOrder()->value('id')
            : null;

        return [
            'vehicle_id' => $vehicleId,
            'camera_id' => Camera::query()->inRandomOrder()->value('id') ?? Camera::factory()->create()->id,
            'blockchain_record_id' => $blockchainRecordId,
            'plate_number' => strtoupper(->faker->bothify('???-####')),
            'confidence' => ->faker->randomFloat(4, 0, 1),
            'detection_time' => ->faker->dateTimeBetween('-14 days', 'now'),
            'is_flagged' => ->faker->boolean(25),
            'is_valid' => ->faker->boolean(90),
            'latitude' => ->faker->boolean(85) ? ->faker->latitude(-90, 90) : null,
            'longitude' => ->faker->boolean(85) ? ->faker->longitude(-180, 180) : null,
        ];
    }
}

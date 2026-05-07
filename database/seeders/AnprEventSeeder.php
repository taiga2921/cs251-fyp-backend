<?php

namespace Database\Seeders;

use App\Models\AnprEvent;
use App\Models\BlockchainRecord;
use App\Models\Camera;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class AnprEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Camera::query()->exists()) {
            return;
        }

        $vehicleIds = Vehicle::query()->pluck('id')->all();
        $blockchainRecordIds = BlockchainRecord::query()->pluck('id')->all();
        $cameraIds = Camera::query()->pluck('id')->all();

        for ($i = 0; $i < 25; $i++) {
            AnprEvent::query()->create([
                'vehicle_id' => fake()->boolean(70) && $vehicleIds !== []
                    ? fake()->randomElement($vehicleIds)
                    : null,
                'camera_id' => fake()->randomElement($cameraIds),
                'blockchain_record_id' => fake()->boolean(50) && $blockchainRecordIds !== []
                    ? fake()->randomElement($blockchainRecordIds)
                    : null,
                'plate_number' => strtoupper(fake()->bothify('???-####')),
                'confidence' => fake()->randomFloat(4, 0, 1),
                'detection_time' => fake()->dateTimeBetween('-14 days', 'now'),
                'is_flagged' => fake()->boolean(25),
                'is_valid' => fake()->boolean(90),
                'latitude' => fake()->boolean(85) ? fake()->latitude(-90, 90) : null,
                'longitude' => fake()->boolean(85) ? fake()->longitude(-180, 180) : null,
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\AnprEvent;
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
            $this->call(CameraSeeder::class);
        }

        if (! Vehicle::query()->exists()) {
            $this->call(VehicleSeeder::class);
        }

        $cameraA = Camera::query()->where('name', 'Gate A Camera')->first() ?? Camera::query()->first();
        $cameraB = Camera::query()->where('name', 'Gate B Camera')->first() ?? Camera::query()->skip(1)->first() ?? $cameraA;
        $vehicleNormal = Vehicle::query()->where('plate_number', 'ABC-1001')->first() ?? Vehicle::query()->first();
        $vehicleFlagged = Vehicle::query()->where('plate_number', 'XYZ-9009')->first() ?? Vehicle::query()->skip(1)->first();

        if ($cameraA === null) {
            return;
        }

        $events = [
            [
                'camera_id' => $cameraA->id,
                'vehicle_id' => $vehicleNormal?->id,
                'plate_number' => $vehicleNormal?->plate_number ?? 'ABC-1001',
                'detection_time' => now()->subMinutes(40),
                'confidence' => 0.9825,
                'is_flagged' => false,
                'is_valid' => true,
                'latitude' => 14.5995123,
                'longitude' => 120.9842222,
                'blockchain_record_id' => null,
            ],
            [
                'camera_id' => ($cameraB ?? $cameraA)->id,
                'vehicle_id' => $vehicleFlagged?->id,
                'plate_number' => $vehicleFlagged?->plate_number ?? 'XYZ-9009',
                'detection_time' => now()->subMinutes(25),
                'confidence' => 0.9450,
                'is_flagged' => true,
                'is_valid' => true,
                'latitude' => 14.6001123,
                'longitude' => 120.9851222,
                'blockchain_record_id' => null,
            ],
            [
                'camera_id' => $cameraA->id,
                'vehicle_id' => null,
                'plate_number' => 'UNK-4040',
                'detection_time' => now()->subMinutes(10),
                'confidence' => 0.8125,
                'is_flagged' => false,
                'is_valid' => false,
                'latitude' => 14.5997123,
                'longitude' => 120.9845222,
                'blockchain_record_id' => null,
            ],
        ];

        foreach ($events as $event) {
            AnprEvent::query()->updateOrCreate(
                [
                    'camera_id' => $event['camera_id'],
                    'plate_number' => $event['plate_number'],
                    'detection_time' => $event['detection_time'],
                ],
                $event
            );
        }
    }
}

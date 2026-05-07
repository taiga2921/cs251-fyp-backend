<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Vehicle::query()->updateOrCreate(
            ['plate_number' => 'ABC-1001'],
            [
                'owner_name' => 'Juan Dela Cruz',
                'vehicle_type' => 'car',
                'status' => 'normal',
                'source' => 'manual',
                'notes' => 'Routine registered vehicle.',
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => 'XYZ-9009'],
            [
                'owner_name' => 'Unknown',
                'vehicle_type' => 'van',
                'status' => 'flagged',
                'source' => 'auto_detected',
                'notes' => 'Flagged from prior incident report.',
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => 'WHT-2026'],
            [
                'owner_name' => 'Security Operations',
                'vehicle_type' => 'pickup',
                'status' => 'whitelist',
                'source' => 'manual',
                'notes' => 'Authorized operations vehicle.',
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $names = [
            'Main Entrance',
            'Parking Lot A',
            'Server Room',
            'Reception Area',
            'Loading Dock',
            'Conference Room B',
            'Ground Floor Lobby',
            'Rooftop Access',
            'Storage Warehouse',
            'Security Checkpoint',
        ];

        foreach ($names as $name) {
            $zoneData = Zone::factory()->make(['name' => $name])->toArray();

            Zone::query()->updateOrCreate(
                ['name' => $name],
                collect($zoneData)->except(['name'])->all()
            );
        }
    }
}

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
            Zone::factory()->create(['name' => $name]);
        }
    }
}

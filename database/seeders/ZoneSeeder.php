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
        $zones = [
            ['name' => 'Main Entrance', 'description' => 'Primary public access point.'],
            ['name' => 'Parking Lot A', 'description' => 'Vehicle entry and staging area.'],
            ['name' => 'Server Room', 'description' => 'Restricted infrastructure zone.'],
            ['name' => 'Reception Area', 'description' => 'Front desk and visitor processing.'],
            ['name' => 'Loading Dock', 'description' => 'Goods receiving and dispatch area.'],
            ['name' => 'Conference Room B', 'description' => 'Meeting room wing B.'],
            ['name' => 'Ground Floor Lobby', 'description' => 'Main interior lobby space.'],
            ['name' => 'Rooftop Access', 'description' => 'Roof access control point.'],
            ['name' => 'Storage Warehouse', 'description' => 'Inventory and supplies storage.'],
            ['name' => 'Security Checkpoint', 'description' => 'Guard-operated verification point.'],
        ];

        foreach ($zones as $zone) {
            Zone::query()->updateOrCreate(
                ['name' => $zone['name']],
                ['description' => $zone['description']]
            );
        }
    }
}

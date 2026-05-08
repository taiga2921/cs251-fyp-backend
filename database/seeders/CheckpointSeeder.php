<?php

namespace Database\Seeders;

use App\Models\Checkpoint;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class CheckpointSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Zone::query()->exists()) {
            $this->call(ZoneSeeder::class);
        }

        $checkpointTemplates = [
            [
                'name' => 'Main Entrance Gate',
                'description' => 'Primary checkpoint for inbound personnel.',
                'latitude' => 14.5995123,
                'longitude' => 120.9842222,
                'radius' => 20,
                'location_type' => 'outdoor',
                'is_active' => true,
            ],
            [
                'name' => 'Security Booth',
                'description' => 'Manual verification checkpoint.',
                'latitude' => 14.5998123,
                'longitude' => 120.9846222,
                'radius' => 20,
                'location_type' => 'outdoor',
                'is_active' => true,
            ],
            [
                'name' => 'Ground Floor Lobby',
                'description' => 'Indoor checkpoint near reception flow.',
                'latitude' => 14.5996123,
                'longitude' => 120.9843222,
                'radius' => 40,
                'location_type' => 'indoor',
                'is_active' => true,
            ],
        ];

        Zone::query()->get()->each(function (Zone $zone) use ($checkpointTemplates): void {
            foreach ($checkpointTemplates as $template) {
                Checkpoint::query()->updateOrCreate(
                    [
                        'zone_id' => $zone->id,
                        'name' => $template['name'],
                    ],
                    [
                        'description' => $template['description'],
                        'latitude' => $template['latitude'],
                        'longitude' => $template['longitude'],
                        'radius' => $template['radius'],
                        'location_type' => $template['location_type'],
                        'is_active' => $template['is_active'],
                    ]
                );
            }
        });
    }
}

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
            Zone::factory()->count(5)->create();
        }

        $checkpointNames = [
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
        ];

        Zone::query()->get()->each(function (Zone $zone) use ($checkpointNames): void {
            $count = fake()->numberBetween(3, 5);
            $names = collect($checkpointNames)->shuffle()->take($count)->values();

            $names->each(function (string $name) use ($zone): void {
                Checkpoint::factory()->create([
                    'zone_id' => $zone->id,
                    'name' => $name,
                ]);
            });
        });
    }
}

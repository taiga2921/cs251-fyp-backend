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
            $count = random_int(3, 5);
            $names = collect($checkpointNames)->shuffle()->take($count)->values();

            $names->each(function (string $name) use ($zone): void {
                $checkpointData = Checkpoint::factory()->make([
                    'zone_id' => $zone->id,
                    'name' => $name,
                ])->toArray();

                Checkpoint::query()->updateOrCreate(
                    [
                        'zone_id' => $zone->id,
                        'name' => $name,
                    ],
                    collect($checkpointData)->except(['zone_id', 'name'])->all()
                );
            });
        });
    }
}

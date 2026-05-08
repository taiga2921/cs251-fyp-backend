<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userId = User::query()->inRandomOrder()->value('id');

        return [
            'name' => ->faker->unique()->randomElement([
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
                'North Perimeter',
                'East Wing Corridor',
                'Basement Level 1',
                'Cafeteria',
                'Executive Suite',
            ]),
            'description' => ->faker->boolean(70) ? ->faker->sentence() : ->faker->paragraph(),
            'created_by' => $userId,
        ];
    }
}

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
            'name' => fake()->unique()->randomElement([
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
            'description' => fake()->boolean(70) ? fake()->sentence() : fake()->paragraph(),
            'created_by' => $userId,
        ];
    }
}

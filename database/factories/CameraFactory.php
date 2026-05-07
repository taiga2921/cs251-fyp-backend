<?php

namespace Database\Factories;

use App\Models\Camera;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Camera>
 */
class CameraFactory extends Factory
{
    protected $model = Camera::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'location' => fake()->boolean(80) ? fake()->streetAddress() : null,
            'rtsp_url' => sprintf(
                'rtsp://%s:%s@%s:%d/stream',
                fake()->userName(),
                fake()->password(8, 12),
                fake()->ipv4(),
                fake()->numberBetween(554, 8554)
            ),
            'ip_address' => fake()->boolean(80) ? fake()->ipv4() : null,
            'port' => fake()->boolean(80) ? fake()->numberBetween(1, 65535) : null,
            'username' => fake()->boolean(80) ? fake()->userName() : null,
            'password' => fake()->boolean(80) ? fake()->password(8, 16) : null,
            'latitude' => fake()->boolean(80) ? fake()->latitude() : null,
            'longitude' => fake()->boolean(80) ? fake()->longitude() : null,
            'resolution_width' => fake()->randomElement([1280, 1920, 2560, 3840]),
            'resolution_height' => fake()->randomElement([720, 1080, 1440, 2160]),
            'is_active' => true,
            'last_seen_at' => fake()->boolean(70) ? now()->subMinutes(fake()->numberBetween(1, 120)) : null,
        ];
    }
}

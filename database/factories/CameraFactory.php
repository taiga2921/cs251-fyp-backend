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
            'name' => ->faker->words(3, true),
            'location' => ->faker->boolean(80) ? ->faker->streetAddress() : null,
            'rtsp_url' => sprintf(
                'rtsp://%s:%s@%s:%d/stream',
                ->faker->userName(),
                ->faker->password(8, 12),
                ->faker->ipv4(),
                ->faker->numberBetween(554, 8554)
            ),
            'ip_address' => ->faker->boolean(80) ? ->faker->ipv4() : null,
            'port' => ->faker->boolean(80) ? ->faker->numberBetween(1, 65535) : null,
            'username' => ->faker->boolean(80) ? ->faker->userName() : null,
            'password' => ->faker->boolean(80) ? ->faker->password(8, 16) : null,
            'latitude' => ->faker->boolean(80) ? ->faker->latitude() : null,
            'longitude' => ->faker->boolean(80) ? ->faker->longitude() : null,
            'resolution_width' => ->faker->randomElement([1280, 1920, 2560, 3840]),
            'resolution_height' => ->faker->randomElement([720, 1080, 1440, 2160]),
            'is_active' => true,
            'last_seen_at' => ->faker->boolean(70) ? now()->subMinutes(->faker->numberBetween(1, 120)) : null,
        ];
    }
}

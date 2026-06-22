<?php

namespace Database\Factories;

use App\Models\AnprEvent;
use App\Models\AnprImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnprImage>
 */
class AnprImageFactory extends Factory
{
    protected $model = AnprImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'anpr_event_id' => AnprEvent::factory(),
            'image_type' => $this->faker->randomElement(['full', 'plate', 'annotated']),
            'file_path' => 'evidence/test-image.jpg',
            'file_size' => $this->faker->numberBetween(1024, 524288),
            'resolution' => '1920x1080',
            'expires_at' => null,
        ];
    }
}

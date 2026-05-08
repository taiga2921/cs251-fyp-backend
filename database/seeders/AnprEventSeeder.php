<?php

namespace Database\Seeders;

use App\Models\AnprEvent;
use App\Models\Camera;
use Illuminate\Database\Seeder;

class AnprEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Camera::query()->exists()) {
            return;
        }

        $targetCount = 25;
        $existingCount = AnprEvent::query()->count();

        if ($existingCount >= $targetCount) {
            return;
        }

        AnprEvent::factory()->count($targetCount - $existingCount)->create();
    }
}

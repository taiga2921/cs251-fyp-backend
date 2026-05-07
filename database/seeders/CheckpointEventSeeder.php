<?php

namespace Database\Seeders;

use App\Models\Checkpoint;
use App\Models\CheckpointEvent;
use App\Models\PatrolSession;
use Illuminate\Database\Seeder;

class CheckpointEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! PatrolSession::query()->exists() || ! Checkpoint::query()->exists()) {
            return;
        }

        CheckpointEvent::factory()->count(25)->create();
    }
}

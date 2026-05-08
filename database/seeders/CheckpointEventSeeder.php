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
        if (! PatrolSession::query()->exists()) {
            $this->call(PatrolSessionSeeder::class);
        }

        if (! Checkpoint::query()->exists()) {
            $this->call(CheckpointSeeder::class);
        }

        $session = PatrolSession::query()->latest('started_at')->first();
        $checkpoints = Checkpoint::query()->orderBy('name')->take(3)->get();

        if ($session === null || $checkpoints->isEmpty()) {
            return;
        }

        foreach ($checkpoints as $index => $checkpoint) {
            $detectedAt = now()->subMinutes(45 - ($index * 10));
            $enteredAt = now()->subMinutes(50 - ($index * 10));
            $processedAt = now()->subMinutes(44 - ($index * 10));

            CheckpointEvent::query()->updateOrCreate(
                [
                    'patrol_session_id' => $session->id,
                    'checkpoint_id' => $checkpoint->id,
                    'detected_at' => $detectedAt,
                ],
                [
                    'entered_at' => $enteredAt,
                    'exited_at' => null,
                    'processed_at' => $processedAt,
                    'detection_type' => 'continuous',
                    'confidence_score' => 97.5 - ($index * 2.5),
                    'status' => 'verified',
                ]
            );
        }
    }
}

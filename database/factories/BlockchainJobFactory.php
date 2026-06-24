<?php

namespace Database\Factories;

use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockchainJob>
 */
class BlockchainJobFactory extends Factory
{
    protected $model = BlockchainJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blockchain_record_id' => BlockchainRecord::factory(),
            'job_type' => fake()->randomElement(['anchor', 'retry_anchor', 'verify', 'refresh_confirmation']),
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 5,
            'next_attempt_at' => now()->addMinutes(5),
            'started_at' => null,
            'finished_at' => null,
            'last_error' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'status' => 'success',
            'attempts' => 1,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'attempts' => fake()->numberBetween(1, 5),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'last_error' => 'Mock job failure for demo data.',
        ]);
    }
}

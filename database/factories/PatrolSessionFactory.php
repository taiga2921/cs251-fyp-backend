<?php

namespace Database\Factories;

use App\Models\BlockchainRecord;
use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatrolSession>
 */
class PatrolSessionFactory extends Factory
{
    protected $model = PatrolSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userId = User::query()->inRandomOrder()->value('id') ?? User::factory()->create()->id;
        $zoneId = Zone::query()->inRandomOrder()->value('id') ?? Zone::factory()->create()->id;
        $status = $this->faker->randomElement(['active', 'completed', 'aborted']);
        $startedAt = $this->faker->dateTimeBetween('-14 days', 'now');

        $endedAt = null;
        if ($status !== 'active' || $this->faker->boolean(40)) {
            $endedAt = $this->faker->dateTimeBetween($startedAt, 'now');
        }

        $blockchainRecordId = null;
        if ($this->faker->boolean(35)) {
            $blockchainRecordId = BlockchainRecord::query()->inRandomOrder()->value('id');
        }

        return [
            'user_id' => $userId,
            'zone_id' => $zoneId,
            'blockchain_record_id' => $blockchainRecordId,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'status' => $status,
        ];
    }
}

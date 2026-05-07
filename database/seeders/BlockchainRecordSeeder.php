<?php

namespace Database\Seeders;

use App\Models\BlockchainRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlockchainRecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (BlockchainRecord::query()->exists()) {
            return;
        }

        for ($i = 0; $i < 12; $i++) {
            $hash = hash('sha256', Str::uuid()->toString().microtime(true).$i);
            $status = fake()->randomElement(['pending', 'confirmed', 'failed']);
            $submittedAt = fake()->dateTimeBetween('-14 days', 'now');
            $confirmedAt = $status === 'confirmed'
                ? fake()->dateTimeBetween($submittedAt, 'now')
                : null;

            BlockchainRecord::query()->create([
                'entity_type' => 'patrol_session',
                'entity_id' => Str::uuid()->toString(),
                'hash' => $hash,
                'network' => fake()->randomElement(['ganache', 'sepolia']),
                'environment' => fake()->randomElement(['development', 'production']),
                'tx_hash' => $status === 'pending' ? null : '0x'.Str::lower(Str::random(64)),
                'block_number' => $status === 'confirmed' ? fake()->numberBetween(100000, 999999) : null,
                'status' => $status,
                'retry_count' => $status === 'failed' ? fake()->numberBetween(1, 3) : 0,
                'error_message' => $status === 'failed' ? fake()->sentence() : null,
                'submitted_at' => $submittedAt,
                'confirmed_at' => $confirmedAt,
            ]);
        }
    }
}

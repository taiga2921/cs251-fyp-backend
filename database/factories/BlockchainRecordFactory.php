<?php

namespace Database\Factories;

use App\Models\BlockchainRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockchainRecord>
 */
class BlockchainRecordFactory extends Factory
{
    protected $model = BlockchainRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'anpr_event',
            'entity_id' => fake()->uuid(),
            'proof_type' => 'entity_created',
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
            'record_hash' => hash('sha256', fake()->uuid()),
            'payload_summary' => [
                'module' => 'anpr',
                'summary' => 'Demo proof metadata only',
            ],
            'network' => fake()->randomElement(['ganache', 'sepolia']),
            'environment' => fake()->randomElement(['local', 'staging', 'production']),
            'chain_id' => null,
            'contract_address' => null,
            'tx_hash' => null,
            'block_number' => null,
            'confirmations' => 0,
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'submitted_at' => null,
            'confirmed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => 'pending',
            'tx_hash' => null,
            'block_number' => null,
            'confirmations' => 0,
            'submitted_at' => null,
            'confirmed_at' => null,
            'last_error' => null,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => 'queued',
            'tx_hash' => null,
            'block_number' => null,
            'confirmations' => 0,
            'submitted_at' => null,
            'confirmed_at' => null,
            'last_error' => null,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => 'submitted',
            'tx_hash' => '0x'.fake()->sha256(),
            'block_number' => null,
            'confirmations' => 0,
            'submitted_at' => now(),
            'confirmed_at' => null,
            'last_error' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'confirmed',
            'chain_id' => 1337,
            'contract_address' => '0x'.substr(fake()->sha256(), 0, 40),
            'tx_hash' => '0x'.fake()->sha256(),
            'block_number' => fake()->numberBetween(1, 999999),
            'confirmations' => fake()->numberBetween(1, 12),
            'submitted_at' => now()->subMinutes(5),
            'confirmed_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'retry_count' => fake()->numberBetween(1, 5),
            'last_error' => 'Mock anchoring failure for demo data.',
            'confirmed_at' => null,
        ]);
    }
}

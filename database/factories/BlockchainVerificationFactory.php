<?php

namespace Database\Factories;

use App\Models\BlockchainRecord;
use App\Models\BlockchainVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockchainVerification>
 */
class BlockchainVerificationFactory extends Factory
{
    protected $model = BlockchainVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $storedHash = hash('sha256', fake()->uuid());

        return [
            'blockchain_record_id' => BlockchainRecord::factory(),
            'verified_by' => null,
            'verification_type' => fake()->randomElement(['manual', 'scheduled', 'api', 'system']),
            'stored_hash' => $storedHash,
            'recomputed_hash' => $storedHash,
            'onchain_hash' => $storedHash,
            'onchain_found' => true,
            'result' => 'valid',
            'error_message' => null,
            'verified_at' => now(),
        ];
    }

    public function valid(): static
    {
        return $this->state(function (): array {
            $hash = hash('sha256', fake()->uuid());

            return [
                'stored_hash' => $hash,
                'recomputed_hash' => $hash,
                'onchain_hash' => $hash,
                'onchain_found' => true,
                'result' => 'valid',
                'error_message' => null,
            ];
        });
    }

    public function tampered(): static
    {
        return $this->state(fn (): array => [
            'stored_hash' => hash('sha256', 'stored-demo'),
            'recomputed_hash' => hash('sha256', 'tampered-demo'),
            'onchain_hash' => hash('sha256', 'stored-demo'),
            'onchain_found' => true,
            'result' => 'tampered',
            'error_message' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'recomputed_hash' => null,
            'onchain_hash' => null,
            'onchain_found' => null,
            'result' => 'pending',
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'recomputed_hash' => null,
            'onchain_hash' => null,
            'onchain_found' => null,
            'result' => 'failed',
            'error_message' => 'Mock verification failure for demo data.',
        ]);
    }

    public function onchainMissing(): static
    {
        return $this->state(function (): array {
            $hash = hash('sha256', fake()->uuid());

            return [
                'stored_hash' => $hash,
                'recomputed_hash' => $hash,
                'onchain_hash' => null,
                'onchain_found' => false,
                'result' => 'onchain_missing',
                'error_message' => null,
            ];
        });
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(function () use ($user): array {
            $resolvedUser = $user ?? User::factory()->create();

            return [
                'verified_by' => $resolvedUser->id,
            ];
        });
    }
}

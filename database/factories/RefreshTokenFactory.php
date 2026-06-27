<?php

namespace Database\Factories;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RefreshToken>
 */
class RefreshTokenFactory extends Factory
{
    protected $model = RefreshToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'token_family' => (string) Str::uuid(),
            'device_name' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'RefreshTokenFactory',
            'expires_at' => now()->addHours(12),
            'revoked_at' => null,
            'rotated_at' => null,
            'last_used_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    public function rotated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rotated_at' => now(),
        ]);
    }
}

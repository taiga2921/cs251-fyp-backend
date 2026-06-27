<?php

namespace Database\Factories;

use App\Models\PasswordSetupToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PasswordSetupToken>
 */
class PasswordSetupTokenFactory extends Factory
{
    protected $model = PasswordSetupToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addHours(24),
            'used_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
        ]);
    }
}

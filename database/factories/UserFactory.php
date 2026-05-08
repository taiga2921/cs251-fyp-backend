<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = $this->faker ?? FakerFactory::create();

        return [
            'name' => $faker->name(),
            'email' => $faker->unique()->safeEmail(),
            'phone' => $faker->phoneNumber(),
            'address' => $faker->address(),
            'role_id' => Role::query()->inRandomOrder()->value('id'),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'two_factor_enabled' => false,
            'profile_version' => 1,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

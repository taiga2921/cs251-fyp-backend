<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::query()->where('name', 'Admin')->first();
        $operatorRole = Role::query()->where('name', 'Security Operator')->first();
        $guardRole = Role::query()->where('name', 'Guard')->first();

        $this->seedUser('Admin User', 'admin@example.com', $adminRole?->id);
        $this->seedUser('Security Operator', 'operator@example.com', $operatorRole?->id);
        $this->seedUser('Guard User', 'guard@example.com', $guardRole?->id);
    }

    private function seedUser(string $name, string $email, ?string $roleId): void
    {
        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role_id' => $roleId,
                'email_verified_at' => now(),
                'two_factor_enabled' => false,
            ]
        );
    }
}

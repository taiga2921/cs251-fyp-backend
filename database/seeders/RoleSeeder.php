<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::query()->updateOrCreate(
            ['name' => 'Admin'],
            ['description' => 'Full system access and user management.']
        );

        Role::query()->updateOrCreate(
            ['name' => 'Security Operator'],
            ['description' => 'Manages security logs and live monitoring.']
        );

        Role::query()->updateOrCreate(
            ['name' => 'Guard'],
            ['description' => 'Patrolling and incident reporting only.']
        );
    }
}

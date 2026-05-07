<?php

namespace Database\Seeders;

use App\Models\PatrolSession;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class PatrolSessionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! User::query()->exists()) {
            $this->call(UserSeeder::class);
        }

        if (! Zone::query()->exists()) {
            $this->call(ZoneSeeder::class);
        }

        PatrolSession::factory()->count(20)->create();
    }
}

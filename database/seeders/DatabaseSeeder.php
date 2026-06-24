<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            // ZoneSeeder::class,
            // CameraSeeder::class,
            // BlockchainRecordSeeder::class, // Opt-in demo blockchain rows (safe mock metadata only).
            // PatrolSessionSeeder::class,
            // CheckpointSeeder::class,
            // CheckpointEventSeeder::class,
            // VehicleSeeder::class,
            // AnprEventSeeder::class,
        ]);
    }
}

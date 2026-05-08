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

        $adminUser = User::query()->where('email', 'admin@example.com')->first() ?? User::query()->first();
        $operatorUser = User::query()->where('email', 'operator@example.com')->first() ?? User::query()->skip(1)->first() ?? $adminUser;
        $guardUser = User::query()->where('email', 'guard@example.com')->first() ?? User::query()->skip(2)->first() ?? $adminUser;

        $mainZone = Zone::query()->where('name', 'Main Entrance')->first() ?? Zone::query()->first();
        $parkingZone = Zone::query()->where('name', 'Parking Lot A')->first() ?? Zone::query()->skip(1)->first() ?? $mainZone;
        $securityZone = Zone::query()->where('name', 'Security Checkpoint')->first() ?? Zone::query()->skip(2)->first() ?? $mainZone;

        if ($adminUser === null || $mainZone === null) {
            return;
        }

        $sessions = [
            [
                'user_id' => $adminUser->id,
                'zone_id' => $mainZone->id,
                'started_at' => now()->subHours(12),
                'ended_at' => now()->subHours(11),
                'status' => 'completed',
                'blockchain_record_id' => null,
            ],
            [
                'user_id' => ($operatorUser ?? $adminUser)->id,
                'zone_id' => ($parkingZone ?? $mainZone)->id,
                'started_at' => now()->subHours(6),
                'ended_at' => now()->subHours(5),
                'status' => 'completed',
                'blockchain_record_id' => null,
            ],
            [
                'user_id' => ($guardUser ?? $adminUser)->id,
                'zone_id' => ($securityZone ?? $mainZone)->id,
                'started_at' => now()->subMinutes(90),
                'ended_at' => null,
                'status' => 'active',
                'blockchain_record_id' => null,
            ],
        ];

        foreach ($sessions as $session) {
            PatrolSession::query()->updateOrCreate(
                [
                    'user_id' => $session['user_id'],
                    'zone_id' => $session['zone_id'],
                    'started_at' => $session['started_at'],
                ],
                $session
            );
        }
    }
}

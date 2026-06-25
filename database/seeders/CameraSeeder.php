<?php

namespace Database\Seeders;

use App\Models\Camera;
use Illuminate\Database\Seeder;

class CameraSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Camera::query()->updateOrCreate(
            ['name' => 'Gate A Camera'],
            [
                'location' => 'Gate A',
                'rtsp_url' => 'rtsp://TapoC200:Dodol@0922@10.42.80.229:554/stream2',
                'ip_address' => '10.42.80.229',
                'port' => 554,
                'username' => 'TapoC200',
                'password' => 'Dodol@0922',
                'latitude' => 14.5995123,
                'longitude' => 120.9842222,
                'resolution_width' => 1920,
                'resolution_height' => 1080,
                'is_active' => true,
                'last_seen_at' => now()->subMinutes(5),
            ]
        );

        Camera::query()->updateOrCreate(
            ['name' => 'Gate B Camera'],
            [
                'location' => 'Gate B',
                'rtsp_url' => 'rtsp://admin:password@192.168.1.11:554/stream1',
                'ip_address' => '192.168.1.11',
                'port' => 554,
                'username' => 'admin',
                'password' => 'password',
                'latitude' => 14.6001123,
                'longitude' => 120.9851222,
                'resolution_width' => 1920,
                'resolution_height' => 1080,
                'is_active' => true,
                'last_seen_at' => now()->subMinutes(3),
            ]
        );
    }
}

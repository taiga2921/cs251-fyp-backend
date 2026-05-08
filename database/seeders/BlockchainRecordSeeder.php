<?php

namespace Database\Seeders;

use App\Models\BlockchainRecord;
use Illuminate\Database\Seeder;

class BlockchainRecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $records = [
            [
                'entity_type' => 'patrol_session',
                'entity_id' => 'seed-ps-001',
                'hash' => 'a3d9714f8a7ce9f8cf7aaf8e0ed22558c7f8f5cb4dfbb9af9f2a7ca81f3a1b10',
                'network' => 'ganache',
                'environment' => 'development',
                'tx_hash' => '0x2f4bf236dc8e8d337ac6f558170fa4f7750eac5d44c51eb33ce2482f05450f5d',
                'block_number' => 120001,
                'status' => 'confirmed',
                'retry_count' => 0,
                'error_message' => null,
                'submitted_at' => now()->subDays(10),
                'confirmed_at' => now()->subDays(10)->addMinutes(5),
            ],
            [
                'entity_type' => 'patrol_session',
                'entity_id' => 'seed-ps-002',
                'hash' => 'b4e9a30df5979b32f1d56ffccd8b180f95b2f2a7dfb2095ae3263896c2f93222',
                'network' => 'sepolia',
                'environment' => 'production',
                'tx_hash' => null,
                'block_number' => null,
                'status' => 'pending',
                'retry_count' => 0,
                'error_message' => null,
                'submitted_at' => now()->subDays(3),
                'confirmed_at' => null,
            ],
            [
                'entity_type' => 'patrol_session',
                'entity_id' => 'seed-ps-003',
                'hash' => 'c8d7b82a9363028f206f39fdd764e46211c16ab16bce3d7558b5e79fc6ea4333',
                'network' => 'ganache',
                'environment' => 'development',
                'tx_hash' => null,
                'block_number' => null,
                'status' => 'failed',
                'retry_count' => 2,
                'error_message' => 'Nonce mismatch encountered during submission.',
                'submitted_at' => now()->subDays(1),
                'confirmed_at' => null,
            ],
        ];

        foreach ($records as $record) {
            BlockchainRecord::query()->updateOrCreate(
                [
                    'hash' => $record['hash'],
                    'network' => $record['network'],
                ],
                $record
            );
        }
    }
}

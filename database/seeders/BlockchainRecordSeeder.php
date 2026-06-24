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
                'entity_id' => '01930000-0000-7000-8000-000000000001',
                'proof_type' => 'validation_result',
                'canonical_version' => 'v1',
                'hash_algorithm' => 'sha256',
                'record_hash' => 'a3d9714f8a7ce9f8cf7aaf8e0ed22558c7f8f5cb4dfbb9af9f2a7ca81f3a1b10',
                'payload_summary' => [
                    'module' => 'patrol',
                    'summary' => 'Demo confirmed patrol validation proof (mock metadata only)',
                ],
                'network' => 'ganache',
                'environment' => 'local',
                'chain_id' => 1337,
                'contract_address' => '0xec6E4aF5AD6bc5ab9AC6AE1C58A4C952e991B79f',
                'tx_hash' => '0x2f4bf236dc8e8d337ac6f558170fa4f7750eac5d44c51eb33ce2482f05450f5d',
                'block_number' => 120001,
                'confirmations' => 1,
                'status' => 'confirmed',
                'retry_count' => 0,
                'last_error' => null,
                'submitted_at' => now()->subDays(10),
                'confirmed_at' => now()->subDays(10)->addMinutes(5),
            ],
            [
                'entity_type' => 'patrol_session',
                'entity_id' => '01930000-0000-7000-8000-000000000002',
                'proof_type' => 'validation_result',
                'canonical_version' => 'v1',
                'hash_algorithm' => 'sha256',
                'record_hash' => 'b4e9a30df5979b32f1d56ffccd8b180f95b2f2a7dfb2095ae3263896c2f93222',
                'payload_summary' => [
                    'module' => 'patrol',
                    'summary' => 'Demo pending proof awaiting anchoring (mock metadata only)',
                ],
                'network' => 'sepolia',
                'environment' => 'staging',
                'tx_hash' => null,
                'block_number' => null,
                'confirmations' => 0,
                'status' => 'pending',
                'retry_count' => 0,
                'last_error' => null,
                'submitted_at' => null,
                'confirmed_at' => null,
            ],
            [
                'entity_type' => 'patrol_session',
                'entity_id' => '01930000-0000-7000-8000-000000000003',
                'proof_type' => 'validation_result',
                'canonical_version' => 'v1',
                'hash_algorithm' => 'sha256',
                'record_hash' => 'c8d7b82a9363028f206f39fdd764e46211c16ab16bce3d7558b5e79fc6ea4333',
                'payload_summary' => [
                    'module' => 'patrol',
                    'summary' => 'Demo failed anchoring attempt (mock metadata only)',
                ],
                'network' => 'ganache',
                'environment' => 'local',
                'tx_hash' => null,
                'block_number' => null,
                'confirmations' => 0,
                'status' => 'failed',
                'retry_count' => 2,
                'last_error' => 'Nonce mismatch encountered during mock submission.',
                'submitted_at' => now()->subDays(1),
                'confirmed_at' => null,
            ],
        ];

        foreach ($records as $record) {
            BlockchainRecord::query()->updateOrCreate(
                [
                    'entity_type' => $record['entity_type'],
                    'entity_id' => $record['entity_id'],
                    'proof_type' => $record['proof_type'],
                    'canonical_version' => $record['canonical_version'],
                    'environment' => $record['environment'],
                ],
                $record
            );
        }
    }
}

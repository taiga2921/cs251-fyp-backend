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
        $targetCount = 12;
        $existingCount = BlockchainRecord::query()->count();

        if ($existingCount >= $targetCount) {
            return;
        }

        BlockchainRecord::factory()->count($targetCount - $existingCount)->create();
    }
}

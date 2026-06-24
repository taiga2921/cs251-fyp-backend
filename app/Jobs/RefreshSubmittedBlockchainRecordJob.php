<?php

namespace App\Jobs;

use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainSubmittedRecordRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshSubmittedBlockchainRecordJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $blockchainRecordId,
        public readonly ?string $expectedBlockchainJobId = null,
    ) {}

    public function handle(BlockchainSubmittedRecordRefreshService $refreshService): void
    {
        $record = BlockchainRecord::query()->find($this->blockchainRecordId);

        if ($record === null) {
            return;
        }

        $refreshService->refreshSubmittedRecord($record, $this->expectedBlockchainJobId);
    }
}

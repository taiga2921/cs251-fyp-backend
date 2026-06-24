<?php

namespace App\Console\Commands;

use App\Jobs\RefreshSubmittedBlockchainRecordJob;
use App\Services\Blockchain\BlockchainSubmittedRecordRefreshService;
use Illuminate\Console\Command;

class RefreshSubmittedBlockchainRecordsCommand extends Command
{
    protected $signature = 'blockchain:refresh-submitted
                            {--network= : Filter by blockchain network}
                            {--environment= : Filter by blockchain environment}
                            {--limit=50 : Maximum records to process}
                            {--record= : Refresh a single blockchain record UUID}
                            {--sync : Refresh records inline instead of dispatching queue jobs}';

    protected $description = 'Refresh submitted blockchain records by re-checking existing transaction receipts and confirmations';

    public function handle(BlockchainSubmittedRecordRefreshService $refreshService): int
    {
        if (! config('blockchain.enabled')) {
            $this->warn('Blockchain is disabled in configuration. Refresh will still scan existing submitted records.');
        }

        $limit = max(1, (int) $this->option('limit'));
        $recordId = $this->option('record');

        $query = $refreshService->eligibleRecordsQuery(
            network: $this->option('network'),
            environment: $this->option('environment'),
            recordId: is_string($recordId) && $recordId !== '' ? $recordId : null,
        );

        $records = $query
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get();

        if ($records->isEmpty()) {
            $this->info('No eligible submitted blockchain records found.');

            return self::SUCCESS;
        }

        $scanned = $records->count();
        $dispatched = 0;
        $confirmed = 0;
        $stillSubmitted = 0;
        $failed = 0;

        foreach ($records as $record) {
            if (! $refreshService->isEligibleForRefresh($record)) {
                continue;
            }

            if ($this->option('sync')) {
                $beforeStatus = $record->status;
                $refreshService->refreshSubmittedRecord($record);
                $record->refresh();

                match ($record->status) {
                    'confirmed' => $confirmed++,
                    'failed' => $failed++,
                    default => $beforeStatus === 'submitted' && $record->isSubmitted() ? $stillSubmitted++ : null,
                };
            } else {
                RefreshSubmittedBlockchainRecordJob::dispatch($record->id);
                $dispatched++;
            }
        }

        $this->info("Records scanned: {$scanned}");

        if ($this->option('sync')) {
            $this->line("Confirmed: {$confirmed}");
            $this->line("Still submitted: {$stillSubmitted}");
            $this->line("Failed: {$failed}");
        } else {
            $this->line("Refresh jobs dispatched: {$dispatched}");
        }

        return self::SUCCESS;
    }
}

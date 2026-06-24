<?php

namespace App\Console\Commands;

use App\Services\Blockchain\BlockchainConfigValidator;
use Illuminate\Console\Command;

class CheckBlockchainConfigCommand extends Command
{
    protected $signature = 'blockchain:check-config';

    protected $description = 'Validate blockchain module configuration';

    public function handle(BlockchainConfigValidator $validator): int
    {
        $result = $validator->validate();

        if (! $result['valid']) {
            $this->error('Blockchain configuration check failed.');

            foreach ($result['errors'] as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $this->info('Blockchain configuration check passed.');

        $summary = $result['summary'];

        if (($summary['status'] ?? '') === 'disabled') {
            $this->line('Status: disabled');

            return self::SUCCESS;
        }

        $this->line('Status: enabled');
        $this->line('Mode: '.($summary['mode'] ?? ''));
        $this->line('Network: '.($summary['network'] ?? ''));
        $this->line('Environment: '.($summary['environment'] ?? ''));
        $this->line('Chain ID: '.($summary['chain_id'] ?? ''));
        $this->line('Contract address: '.($summary['contract_address'] ?? ''));
        $this->line('ABI path: '.($summary['contract_abi_path'] ?? ''));

        if ($this->isNonEmptyString($summary['wallet_address'] ?? null)) {
            $this->line('Wallet address: '.$summary['wallet_address']);
        } else {
            $this->line('Wallet address: [not configured]');
        }

        $this->line(
            'Private key: '.(($summary['private_key_configured'] ?? false) ? '[configured]' : '[not configured]')
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn('- '.$warning);
        }

        return self::SUCCESS;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

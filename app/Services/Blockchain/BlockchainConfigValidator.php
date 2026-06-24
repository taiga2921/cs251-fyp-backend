<?php

namespace App\Services\Blockchain;

class BlockchainConfigValidator
{
    private const MODES = ['local', 'testnet'];

    private const NETWORKS = ['ganache', 'sepolia'];

    private const ENVIRONMENTS = ['local', 'staging', 'production'];

    private const HASH_ALGORITHMS = ['sha256'];

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     summary: array<string, mixed>
     * }
     */
    public function validate(?array $config = null): array
    {
        $preferEnv = $config === null;
        $config = $config ?? config('blockchain', []);

        $errors = [];
        $warnings = [];
        $summary = [
            'enabled' => (bool) ($config['enabled'] ?? false),
        ];

        if (! $summary['enabled']) {
            $summary['status'] = 'disabled';

            return [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
                'summary' => $summary,
            ];
        }

        $summary['status'] = 'enabled';
        $summary['mode'] = (string) ($config['mode'] ?? '');
        $summary['network'] = (string) ($config['network'] ?? '');
        $summary['environment'] = (string) ($config['environment'] ?? '');
        $summary['chain_id'] = (int) ($config['chain_id'] ?? 0);
        $summary['contract_address'] = (string) ($config['contract_address'] ?? '');
        $summary['contract_abi_path'] = (string) ($config['contract_abi_path'] ?? '');
        $summary['wallet_address'] = (string) ($config['wallet_address'] ?? '');
        $summary['private_key_configured'] = $this->isNonEmptyString($config['private_key'] ?? null);

        $this->validateEnumerations($config, $errors);
        $this->validateRequiredEnabledFields($config, $errors);
        $this->validateNumericFields($config, $errors, $preferEnv);
        $this->validateAddresses($config, $errors);
        $this->validateAbiFile($config, $errors);
        $this->validateSepoliaConfiguration($config, $errors);
        $this->validateModeNetworkAlignment($config, $warnings);

        if (! $summary['private_key_configured'] && ($config['network'] ?? '') === 'ganache') {
            $warnings[] = 'Private key not configured; Ganache may use unlocked eth_accounts when available.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateEnumerations(array $config, array &$errors): void
    {
        if (! in_array($config['mode'] ?? null, self::MODES, true)) {
            $errors[] = 'BLOCKCHAIN_MODE must be one of: '.implode(', ', self::MODES).'.';
        }

        if (! in_array($config['network'] ?? null, self::NETWORKS, true)) {
            $errors[] = 'BLOCKCHAIN_NETWORK must be one of: '.implode(', ', self::NETWORKS).'.';
        }

        if (! in_array($config['environment'] ?? null, self::ENVIRONMENTS, true)) {
            $errors[] = 'BLOCKCHAIN_ENVIRONMENT must be one of: '.implode(', ', self::ENVIRONMENTS).'.';
        }

        if (! in_array($config['hash_algorithm'] ?? null, self::HASH_ALGORITHMS, true)) {
            $errors[] = 'BLOCKCHAIN_HASH_ALGORITHM must be one of: '.implode(', ', self::HASH_ALGORITHMS).'.';
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateRequiredEnabledFields(array $config, array &$errors): void
    {
        if (! $this->isNonEmptyString($config['rpc_url'] ?? null)) {
            $errors[] = 'BLOCKCHAIN_RPC_URL is required when blockchain is enabled.';
        }

        if (! $this->isNonEmptyString($config['contract_address'] ?? null)) {
            $errors[] = 'BLOCKCHAIN_CONTRACT_ADDRESS is required when blockchain is enabled.';
        }

        if (! $this->isNonEmptyString($config['contract_abi_path'] ?? null)) {
            $errors[] = 'BLOCKCHAIN_CONTRACT_ABI_PATH is required when blockchain is enabled.';
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateNumericFields(array $config, array &$errors, bool $preferEnv = false): void
    {
        $chainId = $this->resolveNumericValue('BLOCKCHAIN_CHAIN_ID', $config, 'chain_id', $preferEnv);
        if (! $this->isValidPositiveInteger($chainId)) {
            $errors[] = 'BLOCKCHAIN_CHAIN_ID must be a positive integer when blockchain is enabled.';
        }

        $confirmationBlocks = $this->resolveNumericValue(
            'BLOCKCHAIN_CONFIRMATION_BLOCKS',
            $config,
            'confirmation_blocks',
            $preferEnv
        );
        if (! $this->isValidPositiveInteger($confirmationBlocks)) {
            $errors[] = 'BLOCKCHAIN_CONFIRMATION_BLOCKS must be a positive integer (1 or greater) when blockchain is enabled.';
        }

        $maxRetries = $this->resolveNumericValue('BLOCKCHAIN_MAX_RETRIES', $config, 'max_retries', $preferEnv);
        if (! $this->isValidNonNegativeInteger($maxRetries)) {
            $errors[] = 'BLOCKCHAIN_MAX_RETRIES must be a non-negative integer when blockchain is enabled.';
        }

        $retryBaseSeconds = $this->resolveNumericValue(
            'BLOCKCHAIN_RETRY_BASE_SECONDS',
            $config,
            'retry_base_seconds',
            $preferEnv
        );
        if (! $this->isValidNonNegativeInteger($retryBaseSeconds)) {
            $errors[] = 'BLOCKCHAIN_RETRY_BASE_SECONDS must be a non-negative integer when blockchain is enabled.';
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveNumericValue(string $envKey, array $config, string $configKey, bool $preferEnv): mixed
    {
        if ($preferEnv) {
            $envValue = env($envKey);
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }

        return $config[$configKey] ?? null;
    }

    private function isValidPositiveInteger(mixed $value): bool
    {
        $integer = $this->integerValue($value);

        return $integer !== null && $integer >= 1;
    }

    private function isValidNonNegativeInteger(mixed $value): bool
    {
        $integer = $this->integerValue($value);

        return $integer !== null && $integer >= 0;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! preg_match('/^-?\d+$/', $trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateAddresses(array $config, array &$errors): void
    {
        if ($this->isNonEmptyString($config['contract_address'] ?? null)
            && ! $this->isValidEthereumAddress((string) $config['contract_address'])) {
            $errors[] = 'BLOCKCHAIN_CONTRACT_ADDRESS must be a valid 0x-prefixed Ethereum address.';
        }

        if ($this->isNonEmptyString($config['wallet_address'] ?? null)
            && ! $this->isValidEthereumAddress((string) $config['wallet_address'])) {
            $errors[] = 'BLOCKCHAIN_WALLET_ADDRESS must be a valid 0x-prefixed Ethereum address when set.';
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateAbiFile(array $config, array &$errors): void
    {
        $abiPath = (string) ($config['contract_abi_path'] ?? '');
        if ($abiPath === '') {
            return;
        }

        $absolutePath = $this->resolveAbiPath($abiPath);
        if (! is_file($absolutePath)) {
            $errors[] = "BLOCKCHAIN_CONTRACT_ABI_PATH file not found at {$absolutePath}.";

            return;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            $errors[] = "BLOCKCHAIN_CONTRACT_ABI_PATH could not be read at {$absolutePath}.";

            return;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            $errors[] = 'BLOCKCHAIN_CONTRACT_ABI_PATH must contain valid JSON with an abi array.';

            return;
        }

        $abi = $decoded['abi'] ?? null;
        if (! is_array($abi) || $abi === []) {
            $errors[] = 'BLOCKCHAIN_CONTRACT_ABI_PATH must contain a non-empty abi array.';
        }

        if ($this->isNonEmptyString($config['contract_address'] ?? null)
            && isset($decoded['address'])
            && is_string($decoded['address'])
            && strcasecmp($decoded['address'], (string) $config['contract_address']) !== 0) {
            $errors[] = 'Deployment JSON address does not match BLOCKCHAIN_CONTRACT_ADDRESS.';
        }

        if (isset($decoded['chainId'])) {
            $deploymentChainId = $this->integerValue($decoded['chainId']);
            $configuredChainId = $this->integerValue($config['chain_id'] ?? null);

            if ($deploymentChainId !== null
                && $configuredChainId !== null
                && $deploymentChainId !== $configuredChainId) {
                $errors[] = 'Deployment JSON chainId does not match BLOCKCHAIN_CHAIN_ID.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateSepoliaConfiguration(array $config, array &$errors): void
    {
        if (! ($config['enabled'] ?? false)) {
            return;
        }

        if (($config['network'] ?? '') !== 'sepolia') {
            return;
        }

        if (($config['mode'] ?? '') !== 'testnet') {
            $errors[] = 'BLOCKCHAIN_MODE must be testnet when BLOCKCHAIN_NETWORK=sepolia.';
        }

        $chainId = $this->integerValue($config['chain_id'] ?? null);
        if ($chainId !== 11155111) {
            $errors[] = 'BLOCKCHAIN_CHAIN_ID must be 11155111 when BLOCKCHAIN_NETWORK=sepolia.';
        }

        if (! $this->isNonEmptyString($config['wallet_address'] ?? null)) {
            $errors[] = 'BLOCKCHAIN_WALLET_ADDRESS is required when BLOCKCHAIN_NETWORK=sepolia.';
        }

        if (! $this->isNonEmptyString($config['private_key'] ?? null)) {
            $errors[] = 'BLOCKCHAIN_PRIVATE_KEY is required when BLOCKCHAIN_NETWORK=sepolia.';
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $warnings
     */
    private function validateModeNetworkAlignment(array $config, array &$warnings): void
    {
        $mode = (string) ($config['mode'] ?? '');
        $network = (string) ($config['network'] ?? '');

        if ($mode === 'local' && $network !== 'ganache') {
            $warnings[] = 'BLOCKCHAIN_MODE=local is usually paired with BLOCKCHAIN_NETWORK=ganache.';
        }

        if ($mode === 'testnet' && $network !== 'sepolia') {
            $warnings[] = 'BLOCKCHAIN_MODE=testnet is usually paired with BLOCKCHAIN_NETWORK=sepolia.';
        }
    }

    public function resolveAbiPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private function isValidEthereumAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

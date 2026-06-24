<?php

namespace Tests\Feature\Blockchain;

use App\Services\Blockchain\BlockchainConfigValidator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BlockchainConfigurationTest extends TestCase
{
    private BlockchainConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(BlockchainConfigValidator::class);
    }

    public function test_default_config_is_disabled(): void
    {
        config(['blockchain.enabled' => false]);

        $result = $this->validator->validate();

        $this->assertFalse(config('blockchain.enabled'));
        $this->assertTrue($result['valid']);
        $this->assertSame('disabled', $result['summary']['status']);
    }

    public function test_enabled_config_fails_for_malformed_chain_id_strings(): void
    {
        foreach (['1337abc', '1.5', 'abc'] as $chainId) {
            $result = $this->validator->validate($this->enabledConfig([
                'chain_id' => $chainId,
            ]));

            $this->assertFalse($result['valid'], "Expected invalid chain_id: {$chainId}");
            $this->assertContains(
                'BLOCKCHAIN_CHAIN_ID must be a positive integer when blockchain is enabled.',
                $result['errors']
            );
        }
    }

    public function test_enabled_config_fails_for_malformed_max_retries(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'max_retries' => 'abc',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_MAX_RETRIES must be a non-negative integer when blockchain is enabled.',
            $result['errors']
        );
    }

    public function test_enabled_config_fails_for_malformed_retry_base_seconds(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'retry_base_seconds' => '10.5',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_RETRY_BASE_SECONDS must be a non-negative integer when blockchain is enabled.',
            $result['errors']
        );
    }

    public function test_enabled_config_fails_for_malformed_confirmation_blocks(): void
    {
        $contractAddress = '0x'.str_repeat('a', 40);
        $abiPath = $this->createTemporaryAbiFile(
            address: $contractAddress,
            chainId: 1337,
        );

        foreach ([0, 'abc', '1.5'] as $confirmationBlocks) {
            $result = $this->validator->validate($this->enabledConfig([
                'contract_address' => $contractAddress,
                'contract_abi_path' => $abiPath,
                'confirmation_blocks' => $confirmationBlocks,
            ]));

            $this->assertFalse($result['valid'], "Expected invalid confirmation_blocks: {$confirmationBlocks}");
            $this->assertContains(
                'BLOCKCHAIN_CONFIRMATION_BLOCKS must be a positive integer (1 or greater) when blockchain is enabled.',
                $result['errors']
            );
        }
    }

    public function test_disabled_blockchain_config_passes_without_rpc_or_contract_address(): void
    {
        $result = $this->validator->validate([
            'enabled' => false,
            'mode' => 'local',
            'network' => 'ganache',
            'environment' => 'local',
            'chain_id' => 0,
            'rpc_url' => null,
            'contract_address' => null,
            'contract_abi_path' => null,
            'wallet_address' => null,
            'private_key' => null,
            'confirmation_blocks' => 0,
            'max_retries' => 0,
            'retry_base_seconds' => 0,
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('disabled', $result['summary']['status']);
        $this->assertSame([], $result['errors']);
    }

    public function test_enabled_config_fails_when_rpc_url_is_missing(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'rpc_url' => null,
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains('BLOCKCHAIN_RPC_URL is required when blockchain is enabled.', $result['errors']);
    }

    public function test_enabled_config_fails_when_contract_address_is_missing(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'contract_address' => null,
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains('BLOCKCHAIN_CONTRACT_ADDRESS is required when blockchain is enabled.', $result['errors']);
    }

    public function test_enabled_config_fails_for_invalid_contract_address(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'contract_address' => 'not-an-address',
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_CONTRACT_ADDRESS must be a valid 0x-prefixed Ethereum address.',
            $result['errors']
        );
    }

    public function test_enabled_config_fails_for_invalid_chain_id(): void
    {
        $result = $this->validator->validate($this->enabledConfig([
            'chain_id' => 0,
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_CHAIN_ID must be a positive integer when blockchain is enabled.',
            $result['errors']
        );
    }

    public function test_enabled_config_passes_for_valid_local_ganache_style_config(): void
    {
        $abiPath = $this->createTemporaryAbiFile(
            address: '0x'.str_repeat('a', 40),
            chainId: 1337,
        );

        $result = $this->validator->validate($this->enabledConfig([
            'mode' => 'local',
            'network' => 'ganache',
            'environment' => 'local',
            'chain_id' => 1337,
            'rpc_url' => 'http://127.0.0.1:7545',
            'contract_address' => '0x'.str_repeat('a', 40),
            'contract_abi_path' => $abiPath,
        ]));

        $this->assertTrue($result['valid'], implode(', ', $result['errors']));
        $this->assertSame('enabled', $result['summary']['status']);
    }

    public function test_sepolia_testnet_config_passes_with_required_values(): void
    {
        $contractAddress = '0x'.str_repeat('b', 40);
        $abiPath = $this->createTemporaryAbiFile(
            address: $contractAddress,
            chainId: 11155111,
        );

        $result = $this->validator->validate($this->enabledConfig([
            'mode' => 'testnet',
            'network' => 'sepolia',
            'environment' => 'staging',
            'chain_id' => 11155111,
            'rpc_url' => 'https://sepolia.infura.io/v3/example-project-id',
            'contract_address' => $contractAddress,
            'contract_abi_path' => $abiPath,
            'wallet_address' => '0x'.str_repeat('d', 40),
            'private_key' => '0x'.str_repeat('e', 64),
        ]));

        $this->assertTrue($result['valid'], implode(', ', $result['errors']));
    }

    public function test_sepolia_config_fails_without_private_key(): void
    {
        $contractAddress = '0x'.str_repeat('b', 40);
        $abiPath = $this->createTemporaryAbiFile(
            address: $contractAddress,
            chainId: 11155111,
        );

        $result = $this->validator->validate($this->enabledConfig([
            'mode' => 'testnet',
            'network' => 'sepolia',
            'environment' => 'staging',
            'chain_id' => 11155111,
            'rpc_url' => 'https://sepolia.infura.io/v3/example-project-id',
            'contract_address' => $contractAddress,
            'contract_abi_path' => $abiPath,
            'wallet_address' => '0x'.str_repeat('d', 40),
            'private_key' => null,
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_PRIVATE_KEY is required when BLOCKCHAIN_NETWORK=sepolia.',
            $result['errors']
        );
    }

    public function test_sepolia_config_fails_for_wrong_chain_id(): void
    {
        $contractAddress = '0x'.str_repeat('b', 40);
        $abiPath = $this->createTemporaryAbiFile(
            address: $contractAddress,
            chainId: 11155111,
        );

        $result = $this->validator->validate($this->enabledConfig([
            'mode' => 'testnet',
            'network' => 'sepolia',
            'environment' => 'staging',
            'chain_id' => 1337,
            'rpc_url' => 'https://sepolia.infura.io/v3/example-project-id',
            'contract_address' => $contractAddress,
            'contract_abi_path' => $abiPath,
            'wallet_address' => '0x'.str_repeat('d', 40),
            'private_key' => '0x'.str_repeat('e', 64),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'BLOCKCHAIN_CHAIN_ID must be 11155111 when BLOCKCHAIN_NETWORK=sepolia.',
            $result['errors']
        );
    }

    public function test_artisan_command_returns_non_zero_on_invalid_enabled_config(): void
    {
        config([
            'blockchain' => $this->enabledConfig([
                'rpc_url' => null,
                'contract_address' => null,
            ]),
        ]);

        $this->artisan('blockchain:check-config')
            ->assertExitCode(1)
            ->expectsOutputToContain('Blockchain configuration check failed.');
    }

    public function test_artisan_command_never_prints_raw_private_key(): void
    {
        $secretKey = '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
        $abiPath = $this->createTemporaryAbiFile(
            address: '0x'.str_repeat('c', 40),
            chainId: 1337,
        );

        config([
            'blockchain' => $this->enabledConfig([
                'contract_address' => '0x'.str_repeat('c', 40),
                'contract_abi_path' => $abiPath,
                'private_key' => $secretKey,
            ]),
        ]);

        $exitCode = Artisan::call('blockchain:check-config');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($secretKey, $output);
        $this->assertStringContainsString('Private key: [configured]', $output);
    }

    public function test_env_example_contains_required_blockchain_variables(): void
    {
        $contents = file_get_contents(base_path('.env.example'));
        $this->assertIsString($contents);

        foreach ([
            'BLOCKCHAIN_ENABLED',
            'BLOCKCHAIN_MODE',
            'BLOCKCHAIN_NETWORK',
            'BLOCKCHAIN_ENVIRONMENT',
            'BLOCKCHAIN_CHAIN_ID',
            'BLOCKCHAIN_RPC_URL',
            'BLOCKCHAIN_CONTRACT_ADDRESS',
            'BLOCKCHAIN_CONTRACT_ABI_PATH',
            'BLOCKCHAIN_WALLET_ADDRESS',
            'BLOCKCHAIN_PRIVATE_KEY',
            'BLOCKCHAIN_CONFIRMATION_BLOCKS',
            'BLOCKCHAIN_MAX_RETRIES',
            'BLOCKCHAIN_RETRY_BASE_SECONDS',
            'BLOCKCHAIN_CANONICAL_VERSION',
            'BLOCKCHAIN_HASH_ALGORITHM',
        ] as $variable) {
            $this->assertStringContainsString($variable.'=', $contents, "Missing {$variable} in .env.example");
        }
    }

    public function test_gitignore_protects_env_files(): void
    {
        $contents = file_get_contents(base_path('.gitignore'));
        $this->assertIsString($contents);
        $this->assertStringContainsString('.env', $contents);
        $this->assertStringContainsString('.env.*', $contents);
        $this->assertStringContainsString('!.env.example', $contents);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enabledConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'mode' => 'local',
            'network' => 'ganache',
            'environment' => 'local',
            'chain_id' => 1337,
            'rpc_url' => 'http://127.0.0.1:7545',
            'contract_address' => '0x'.str_repeat('a', 40),
            'contract_abi_path' => '../blockchain-ethereum-v1/deployments/ganache/EvidenceStore.json',
            'wallet_address' => null,
            'private_key' => null,
            'confirmation_blocks' => 1,
            'max_retries' => 5,
            'retry_base_seconds' => 10,
            'canonical_version' => 'v1',
            'hash_algorithm' => 'sha256',
        ], $overrides);
    }

    private function createTemporaryAbiFile(string $address, int $chainId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-store-');
        $this->assertNotFalse($path);

        file_put_contents($path, json_encode([
            'contractName' => 'EvidenceStore',
            'address' => $address,
            'chainId' => $chainId,
            'abi' => [
                [
                    'type' => 'function',
                    'name' => 'verifyHash',
                    'inputs' => [
                        ['internalType' => 'bytes32', 'name' => 'hash', 'type' => 'bytes32'],
                    ],
                    'outputs' => [
                        ['internalType' => 'bool', 'name' => '', 'type' => 'bool'],
                    ],
                    'stateMutability' => 'view',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}

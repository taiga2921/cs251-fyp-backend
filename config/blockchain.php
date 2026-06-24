<?php

return [
    'enabled' => (bool) env('BLOCKCHAIN_ENABLED', false),

    'mode' => env('BLOCKCHAIN_MODE', 'local'),
    'network' => env('BLOCKCHAIN_NETWORK', 'ganache'),
    'environment' => env('BLOCKCHAIN_ENVIRONMENT', 'local'),

    'chain_id' => (int) env('BLOCKCHAIN_CHAIN_ID', 1337),
    'rpc_url' => env('BLOCKCHAIN_RPC_URL'),

    'contract_address' => env('BLOCKCHAIN_CONTRACT_ADDRESS'),
    'contract_abi_path' => env('BLOCKCHAIN_CONTRACT_ABI_PATH', '../blockchain-ethereum-v1/deployments/ganache/EvidenceStore.json'),

    'wallet_address' => env('BLOCKCHAIN_WALLET_ADDRESS'),
    'private_key' => env('BLOCKCHAIN_PRIVATE_KEY'),

    'confirmation_blocks' => (int) env('BLOCKCHAIN_CONFIRMATION_BLOCKS', 1),
    'max_retries' => (int) env('BLOCKCHAIN_MAX_RETRIES', 5),
    'retry_base_seconds' => (int) env('BLOCKCHAIN_RETRY_BASE_SECONDS', 10),

    'canonical_version' => env('BLOCKCHAIN_CANONICAL_VERSION', 'v1'),
    'hash_algorithm' => env('BLOCKCHAIN_HASH_ALGORITHM', 'sha256'),
];

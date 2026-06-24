<?php

namespace App\Services\Blockchain;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class EthereumRpcClient
{
    public const STORE_HASH_SELECTOR = '0x7fe88885';

    public function chainId(): int
    {
        $result = $this->rpc('eth_chainId');

        if (! is_string($result)) {
            throw new RuntimeException('Ethereum RPC eth_chainId returned an invalid response.');
        }

        return $this->hexQuantityToInt($result);
    }

    public function blockNumber(): int
    {
        $result = $this->rpc('eth_blockNumber');

        if (! is_string($result)) {
            throw new RuntimeException('Ethereum RPC eth_blockNumber returned an invalid response.');
        }

        return $this->hexQuantityToInt($result);
    }

    /**
     * @return list<string>
     */
    public function accounts(): array
    {
        $result = $this->rpc('eth_accounts');

        if (! is_array($result)) {
            throw new RuntimeException('Ethereum RPC eth_accounts returned an invalid response.');
        }

        return array_values(array_map(
            fn (mixed $account): string => $this->normalizeAddress((string) $account),
            $result
        ));
    }

    public function resolveSenderAddress(): string
    {
        $configuredWallet = config('blockchain.wallet_address');

        if (is_string($configuredWallet) && trim($configuredWallet) !== '') {
            return $this->normalizeAddress($configuredWallet);
        }

        $accounts = $this->accounts();

        if ($accounts === []) {
            throw new RuntimeException('No Ethereum sender account is available from eth_accounts.');
        }

        return $accounts[0];
    }

    public function storeHash(string $recordHash, ?string $contractAddress = null): string
    {
        $this->assertConfiguredChainIdMatchesLiveChain();

        $to = $this->resolveContractAddress($contractAddress);
        $from = $this->resolveSenderAddress();
        $data = $this->encodeStoreHashCallData($recordHash);

        $result = $this->rpc('eth_sendTransaction', [[
            'from' => $from,
            'to' => $to,
            'data' => $data,
        ]]);

        if (! is_string($result) || ! $this->isValidTransactionHash($result)) {
            throw new RuntimeException('Ethereum RPC eth_sendTransaction returned an invalid transaction hash.');
        }

        return strtolower($result);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function transactionReceipt(string $txHash): ?array
    {
        $result = $this->rpc('eth_getTransactionReceipt', [$txHash]);

        if ($result === null) {
            return null;
        }

        if (! is_array($result)) {
            throw new RuntimeException('Ethereum RPC eth_getTransactionReceipt returned an invalid response.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function confirmationsForReceipt(array $receipt): int
    {
        $blockNumberHex = $receipt['blockNumber'] ?? null;

        if (! is_string($blockNumberHex) || $blockNumberHex === '') {
            return 0;
        }

        $receiptBlock = $this->hexQuantityToInt($blockNumberHex);
        $latestBlock = $this->blockNumber();

        return max(0, $latestBlock - $receiptBlock + 1);
    }

    public function encodeStoreHashCallData(string $recordHash): string
    {
        $normalizedHash = $this->normalizeRecordHash($recordHash);

        return self::STORE_HASH_SELECTOR.substr($normalizedHash, 2);
    }

    public function normalizeRecordHash(string $recordHash): string
    {
        $normalized = strtolower(trim($recordHash));

        if (str_starts_with($normalized, '0x')) {
            $normalized = substr($normalized, 2);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            throw new InvalidArgumentException(
                'Record hash must be a 64-character lowercase hexadecimal SHA-256 value.'
            );
        }

        return '0x'.$normalized;
    }

    public function hexQuantityToInt(string $quantity): int
    {
        $hex = strtolower(ltrim($quantity, '0x'));

        if ($hex === '') {
            return 0;
        }

        if (! preg_match('/^[0-9a-f]+$/', $hex)) {
            throw new RuntimeException('Invalid Ethereum hex quantity.');
        }

        return (int) hexdec($hex);
    }

    public function isValidEthereumAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
    }

    public function normalizeAddress(string $address): string
    {
        if (! $this->isValidEthereumAddress($address)) {
            throw new InvalidArgumentException('Invalid Ethereum address format.');
        }

        return strtolower($address);
    }

    public function resolveContractAddress(?string $contractAddress = null): string
    {
        $candidate = $contractAddress ?: config('blockchain.contract_address');

        if (! is_string($candidate) || trim($candidate) === '') {
            throw new RuntimeException('Blockchain contract address is not configured.');
        }

        return $this->normalizeAddress($candidate);
    }

    private function assertConfiguredChainIdMatchesLiveChain(): void
    {
        $configuredChainId = config('blockchain.chain_id');

        if ($configuredChainId === null || $configuredChainId === '') {
            return;
        }

        $expectedChainId = is_int($configuredChainId)
            ? $configuredChainId
            : $this->hexQuantityToInt((string) $configuredChainId);

        if ($expectedChainId <= 0) {
            return;
        }

        $liveChainId = $this->chainId();

        if ($liveChainId !== $expectedChainId) {
            throw new RuntimeException(
                "Ethereum chain ID mismatch: configured {$expectedChainId}, RPC returned {$liveChainId}."
            );
        }
    }

    private function isValidTransactionHash(string $txHash): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash);
    }

    private function rpc(string $method, array $params = []): mixed
    {
        $rpcUrl = config('blockchain.rpc_url');

        if (! is_string($rpcUrl) || trim($rpcUrl) === '') {
            throw new RuntimeException('Blockchain RPC URL is not configured.');
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ethereum RPC HTTP request failed.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Ethereum RPC returned a non-JSON response.');
        }

        if (array_key_exists('error', $payload) && $payload['error'] !== null) {
            throw new RuntimeException($this->normalizeRpcError($payload['error']));
        }

        return $payload['result'] ?? null;
    }

    /**
     * @param  array<string, mixed>|string  $error
     */
    private function normalizeRpcError(array|string $error): string
    {
        if (is_string($error)) {
            return $this->sanitizeMessage($error);
        }

        $message = $error['message'] ?? 'Unknown Ethereum RPC error.';
        $code = $error['code'] ?? null;

        if ($code !== null) {
            return $this->sanitizeMessage("Ethereum RPC error ({$code}): {$message}");
        }

        return $this->sanitizeMessage((string) $message);
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/', '[rpc-url-redacted]', $message) ?? $message;
        $message = preg_replace('/0x[a-fA-F0-9]{64}/', '[secret-redacted]', $message) ?? $message;

        return trim($message);
    }
}

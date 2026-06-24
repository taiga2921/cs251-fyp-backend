<?php

namespace App\Services\Blockchain;

use InvalidArgumentException;
use RuntimeException;
use Web3p\EthereumTx\Transaction;

class EthereumTransactionSigner
{
    /**
     * @param  array{
     *     nonce: string,
     *     to: string,
     *     gas: string,
     *     gasPrice: string,
     *     value: string,
     *     data: string,
     *     chainId: int
     * }  $transaction
     */
    public function signLegacyTransaction(array $transaction, string $privateKey): string
    {
        if (! extension_loaded('gmp')) {
            throw new RuntimeException('PHP ext-gmp is required for signed Ethereum transactions.');
        }

        $normalizedKey = $this->normalizePrivateKey($privateKey);

        $signed = (new Transaction([
            'nonce' => $transaction['nonce'],
            'to' => $transaction['to'],
            'gas' => $transaction['gas'],
            'gasPrice' => $transaction['gasPrice'],
            'value' => $transaction['value'],
            'data' => $transaction['data'],
            'chainId' => $transaction['chainId'],
        ]))->sign($normalizedKey);

        return '0x'.$signed;
    }

    public function normalizePrivateKey(string $privateKey): string
    {
        $normalized = strtolower(trim($privateKey));

        if (str_starts_with($normalized, '0x')) {
            $normalized = substr($normalized, 2);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            throw new InvalidArgumentException('Blockchain private key must be a 64-character hexadecimal value.');
        }

        return $normalized;
    }
}

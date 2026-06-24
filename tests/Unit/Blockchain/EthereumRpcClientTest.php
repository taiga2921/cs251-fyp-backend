<?php

namespace Tests\Unit\Blockchain;

use App\Services\Blockchain\EthereumRpcClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EthereumRpcClientTest extends TestCase
{
    private EthereumRpcClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
        ]);

        $this->client = new EthereumRpcClient;
    }

    public function test_encodes_store_hash_transaction_data_correctly(): void
    {
        $recordHash = str_repeat('c', 64);

        $this->assertSame(
            '0x7fe88885'.str_repeat('c', 64),
            $this->client->encodeStoreHashCallData($recordHash)
        );
    }

    public function test_normalizes_record_hash_to_bytes32(): void
    {
        $this->assertSame(
            '0x'.str_repeat('d', 64),
            $this->client->normalizeRecordHash('0x'.str_repeat('D', 64))
        );
    }

    public function test_normalizes_record_hash_that_starts_with_zero(): void
    {
        $hash = '0'.str_repeat('a', 63);

        $this->assertSame('0x'.$hash, $this->client->normalizeRecordHash($hash));
        $this->assertSame('0x'.$hash, $this->client->normalizeRecordHash('0x'.$hash));
    }

    public function test_normalizes_record_hash_without_stripping_leading_zero_nibbles(): void
    {
        $hash = '250761c43b650fec1ebc3202eb549205de14b591a560f70d22f57a1aca4ec40e';

        $this->assertSame('0x'.$hash, $this->client->normalizeRecordHash($hash));
        $this->assertSame('0x'.$hash, $this->client->normalizeRecordHash('0x'.$hash));
    }

    #[DataProvider('malformedRecordHashProvider')]
    public function test_rejects_malformed_record_hashes(string $recordHash): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->normalizeRecordHash($recordHash);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedRecordHashProvider(): array
    {
        return [
            'too short' => [str_repeat('a', 63)],
            'non hex' => [str_repeat('z', 64)],
            'empty' => [''],
        ];
    }

    public function test_reads_eth_chain_id_and_converts_hex_quantities_to_integers(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x539',
            ]),
        ]);

        $this->assertSame(1337, $this->client->chainId());
        $this->assertSame(1337, $this->client->hexQuantityToInt('0x539'));
    }

    public function test_sends_eth_send_transaction_with_expected_from_to_and_data(): void
    {
        $recordHash = str_repeat('e', 64);
        $txHash = '0x'.str_repeat('1', 64);

        Http::fake(function ($request) use ($txHash) {
            $body = json_decode($request->body(), true);

            if (($body['method'] ?? null) === 'eth_chainId') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']);
            }

            if (($body['method'] ?? null) === 'eth_sendTransaction') {
                $transaction = $body['params'][0] ?? [];

                $this->assertSame('0x'.str_repeat('b', 40), $transaction['from'] ?? null);
                $this->assertSame('0x'.str_repeat('a', 40), $transaction['to'] ?? null);
                $this->assertSame('0x7fe88885'.str_repeat('e', 64), $transaction['data'] ?? null);

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
            ], 500);
        });

        $result = $this->client->storeHash($recordHash);

        $this->assertSame($txHash, $result);
    }

    public function test_uses_first_eth_accounts_value_when_wallet_address_is_empty(): void
    {
        config(['blockchain.wallet_address' => null]);

        $recordHash = str_repeat('f', 64);
        $sender = '0x'.str_repeat('9', 40);
        $txHash = '0x'.str_repeat('2', 64);
        $capturedFrom = null;

        Http::fake(function ($request) use ($sender, $txHash, &$capturedFrom) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_accounts' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [$sender]]),
                'eth_sendTransaction' => tap(Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]), function () use ($body, &$capturedFrom) {
                    $capturedFrom = $body['params'][0]['from'] ?? null;
                }),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->client->storeHash($recordHash);

        $this->assertSame($sender, $capturedFrom);
    }

    public function test_throws_clear_exception_on_json_rpc_error(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Sender account not authorized',
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum RPC error (-32000): Sender account not authorized');

        $this->client->chainId();
    }

    public function test_detects_chain_id_mismatch(): void
    {
        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x1',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum chain ID mismatch: configured 1337, RPC returned 1.');

        $this->client->storeHash(str_repeat('a', 64));
    }
}

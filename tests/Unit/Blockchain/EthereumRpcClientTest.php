<?php

namespace Tests\Unit\Blockchain;

use App\Services\Blockchain\BlockchainRetryService;
use App\Services\Blockchain\EthereumRpcClient;
use App\Services\Blockchain\EthereumTransactionSigner;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class EthereumRpcClientTest extends TestCase
{
    private EthereumRpcClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.enabled' => true,
            'blockchain.mode' => 'local',
            'blockchain.network' => 'ganache',
            'blockchain.environment' => 'local',
            'blockchain.rpc_url' => 'http://127.0.0.1:7545',
            'blockchain.chain_id' => 1337,
            'blockchain.contract_address' => '0x'.str_repeat('a', 40),
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.private_key' => null,
        ]);

        $this->client = new EthereumRpcClient(new BlockchainRetryService);
    }

    public function test_default_transaction_signer_can_be_resolved_without_mutating_readonly_property(): void
    {
        $client = new EthereumRpcClient(new BlockchainRetryService);

        $method = new ReflectionMethod(EthereumRpcClient::class, 'transactionSigner');
        $method->setAccessible(true);

        $signer = $method->invoke($client);

        $this->assertInstanceOf(EthereumTransactionSigner::class, $signer);
        $this->assertNotSame($signer, $method->invoke($client));
    }

    public function test_injected_transaction_signer_is_reused(): void
    {
        $customSigner = new EthereumTransactionSigner;
        $client = new EthereumRpcClient(new BlockchainRetryService, $customSigner);

        $method = new ReflectionMethod(EthereumRpcClient::class, 'transactionSigner');
        $method->setAccessible(true);

        $this->assertSame($customSigner, $method->invoke($client));
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

    public function test_encodes_verify_hash_call_data_correctly(): void
    {
        $recordHash = str_repeat('c', 64);

        $this->assertSame(
            '0xef020f4a'.str_repeat('c', 64),
            $this->client->encodeVerifyHashCallData($recordHash)
        );
    }

    public function test_verify_hash_returns_true_from_abi_bool_true(): void
    {
        $recordHash = str_repeat('d', 64);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1']),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->assertTrue($this->client->verifyHash($recordHash));
    }

    public function test_verify_hash_returns_false_from_abi_bool_false(): void
    {
        $recordHash = str_repeat('e', 64);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 64)]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->assertFalse($this->client->verifyHash($recordHash));
    }

    public function test_verify_hash_accepts_uppercase_abi_bool_true(): void
    {
        $recordHash = str_repeat('a', 64);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x'.strtoupper(str_repeat('0', 63).'1'),
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->assertTrue($this->client->verifyHash($recordHash));
    }

    public function test_verify_hash_accepts_whitespace_around_valid_abi_bool(): void
    {
        $recordHash = str_repeat('b', 64);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '  0x'.str_repeat('0', 64)."  \n",
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->assertFalse($this->client->verifyHash($recordHash));
    }

    #[DataProvider('malformedAbiBoolProvider')]
    public function test_verify_hash_rejects_malformed_abi_bool_responses(mixed $ethCallResult): void
    {
        Http::fake(function ($request) use ($ethCallResult) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $ethCallResult]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed ABI-encoded bool');

        $this->client->verifyHash(str_repeat('a', 64));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedAbiBoolProvider(): array
    {
        return [
            'empty hex' => ['0x'],
            'short zero' => ['0x0'],
            'short one' => ['0x1'],
            'non abi word' => ['0xdeadbeef'],
            'too short' => ['0x'.str_repeat('a', 63)],
            'too long' => ['0x'.str_repeat('a', 65)],
            'non zero non one word' => ['0x'.str_repeat('0', 63).'2'],
        ];
    }

    public function test_verify_hash_rejects_non_string_eth_call_result(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 1]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid bool response');

        $this->client->verifyHash(str_repeat('a', 64));
    }

    public function test_verify_hash_calls_configured_contract_address(): void
    {
        $recordHash = str_repeat('f', 64);
        $capturedTo = null;

        Http::fake(function ($request) use (&$capturedTo) {
            $body = json_decode($request->body(), true);

            if (($body['method'] ?? null) === 'eth_call') {
                $capturedTo = $body['params'][0]['to'] ?? null;
            }

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_repeat('0', 63).'1']),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->client->verifyHash($recordHash);

        $this->assertSame('0x'.str_repeat('a', 40), $capturedTo);
    }

    public function test_verify_hash_performs_configured_chain_id_check(): void
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

        $this->client->verifyHash(str_repeat('a', 64));
    }

    public function test_verify_hash_rpc_errors_are_sanitized(): void
    {
        config([
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);

        Http::fake([
            'http://127.0.0.1:7545' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'Connection refused at http://127.0.0.1:7545 key='.config('blockchain.private_key'),
                ],
            ]),
        ]);

        try {
            $this->client->verifyHash(str_repeat('a', 64));
            $this->fail('Expected verifyHash to throw on RPC error.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringNotContainsString('http://127.0.0.1:7545', $message);
            $this->assertStringNotContainsString((string) config('blockchain.private_key'), $message);
            $this->assertStringContainsString('[rpc-url-redacted]', $message);
        }
    }

    public function test_sepolia_store_hash_sends_eth_send_raw_transaction(): void
    {
        config([
            'blockchain.network' => 'sepolia',
            'blockchain.mode' => 'testnet',
            'blockchain.chain_id' => 11155111,
            'blockchain.wallet_address' => '0x'.str_repeat('b', 40),
            'blockchain.private_key' => '0x'.str_repeat('c', 64),
        ]);

        $recordHash = str_repeat('e', 64);
        $txHash = '0x'.str_repeat('3', 64);
        $capturedMethod = null;
        $capturedSignedTx = null;

        $signer = new class extends EthereumTransactionSigner
        {
            public function signLegacyTransaction(array $transaction, string $privateKey): string
            {
                return '0x'.str_repeat('a', 128);
            }
        };

        $client = new EthereumRpcClient(new BlockchainRetryService, $signer);

        Http::fake(function ($request) use ($txHash, &$capturedMethod, &$capturedSignedTx) {
            $body = json_decode($request->body(), true);
            $method = $body['method'] ?? null;
            $capturedMethod = $method;

            return match ($method) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xaa36a7']),
                'eth_getTransactionCount' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']),
                'eth_gasPrice' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3b9aca00']),
                'eth_estimateGas' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x5208']),
                'eth_sendRawTransaction' => tap(
                    Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]),
                    function () use ($body, &$capturedSignedTx) {
                        $capturedSignedTx = $body['params'][0] ?? null;
                    }
                ),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $result = $client->storeHash($recordHash);

        $this->assertSame($txHash, $result);
        $this->assertSame('eth_sendRawTransaction', $capturedMethod);
        $this->assertSame('0x'.str_repeat('a', 128), $capturedSignedTx);
    }

    public function test_ganache_store_hash_does_not_use_eth_send_raw_transaction(): void
    {
        config([
            'blockchain.network' => 'ganache',
            'blockchain.mode' => 'local',
        ]);

        $recordHash = str_repeat('f', 64);
        $txHash = '0x'.str_repeat('4', 64);
        $methods = [];

        Http::fake(function ($request) use ($txHash, &$methods) {
            $body = json_decode($request->body(), true);
            $method = $body['method'] ?? null;
            $methods[] = $method;

            return match ($method) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x539']),
                'eth_sendTransaction' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $txHash]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->client->storeHash($recordHash);

        $this->assertContains('eth_sendTransaction', $methods);
        $this->assertNotContains('eth_sendRawTransaction', $methods);
    }

    public function test_sepolia_store_hash_requires_private_key(): void
    {
        config([
            'blockchain.network' => 'sepolia',
            'blockchain.mode' => 'testnet',
            'blockchain.chain_id' => 11155111,
            'blockchain.private_key' => null,
        ]);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xaa36a7']),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private key is required');

        $this->client->storeHash(str_repeat('a', 64));
    }

    public function test_sepolia_raw_transaction_errors_are_sanitized(): void
    {
        config([
            'blockchain.network' => 'sepolia',
            'blockchain.mode' => 'testnet',
            'blockchain.chain_id' => 11155111,
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);

        $signer = new class extends EthereumTransactionSigner
        {
            public function signLegacyTransaction(array $transaction, string $privateKey): string
            {
                return '0x'.str_repeat('b', 128);
            }
        };

        $client = new EthereumRpcClient(new BlockchainRetryService, $signer);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xaa36a7']),
                'eth_getTransactionCount' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']),
                'eth_gasPrice' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3b9aca00']),
                'eth_estimateGas' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x5208']),
                'eth_sendRawTransaction' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => [
                        'code' => -32000,
                        'message' => 'key='.config('blockchain.private_key').' url=https://sepolia.infura.io/v3/secret',
                    ],
                ]),
                default => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32601, 'message' => 'Unhandled RPC method in test.'],
                ], 500),
            };
        });

        try {
            $client->storeHash(str_repeat('a', 64));
            $this->fail('Expected storeHash to throw on RPC error.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringNotContainsString((string) config('blockchain.private_key'), $message);
            $this->assertStringNotContainsString('https://sepolia.infura.io', $message);
            $this->assertStringContainsString('[rpc-url-redacted]', $message);
        }
    }
}

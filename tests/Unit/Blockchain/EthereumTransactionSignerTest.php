<?php

namespace Tests\Unit\Blockchain;

use App\Services\Blockchain\EthereumTransactionSigner;
use InvalidArgumentException;
use Tests\TestCase;

class EthereumTransactionSignerTest extends TestCase
{
    public function test_normalizes_private_key_with_0x_prefix(): void
    {
        $signer = new EthereumTransactionSigner;

        $this->assertSame(
            str_repeat('a', 64),
            $signer->normalizePrivateKey('0x'.str_repeat('A', 64))
        );
    }

    public function test_rejects_invalid_private_key(): void
    {
        $signer = new EthereumTransactionSigner;

        $this->expectException(InvalidArgumentException::class);

        $signer->normalizePrivateKey('0x1234');
    }
}

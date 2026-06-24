<?php

namespace App\Services\Blockchain;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BlockchainRetryService
{
    public function maxAttempts(): int
    {
        $configured = config('blockchain.max_retries', 5);

        if (! is_int($configured) && ! is_string($configured)) {
            return 5;
        }

        $normalized = (int) $configured;

        return max(1, $normalized);
    }

    public function retryBaseSeconds(): int
    {
        $configured = config('blockchain.retry_base_seconds', 10);

        if (! is_int($configured) && ! is_string($configured)) {
            return 10;
        }

        $normalized = (int) $configured;

        return max(1, $normalized);
    }

    public function calculateDelaySeconds(int $attemptNumber): int
    {
        $retryBaseSeconds = $this->retryBaseSeconds();
        $normalizedAttempt = max(1, $attemptNumber);

        return $retryBaseSeconds * (2 ** max(0, $normalizedAttempt - 1));
    }

    public function nextAttemptAt(int $attemptNumber, ?CarbonInterface $from = null): CarbonInterface
    {
        $base = $from ?? now();

        return $base->copy()->addSeconds($this->calculateDelaySeconds($attemptNumber));
    }

    public function canRetry(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts();
    }

    public function sanitizeError(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/', '[rpc-url-redacted]', $message) ?? $message;
        $message = preg_replace('/0x[a-fA-F0-9]{64}/', '[secret-redacted]', $message) ?? $message;
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', '[token-redacted]', $message) ?? $message;

        $privateKey = config('blockchain.private_key');
        if (is_string($privateKey) && $privateKey !== '') {
            $message = str_replace($privateKey, '[secret-redacted]', $message);
        }

        return mb_substr(trim($message), 0, 1000);
    }
}

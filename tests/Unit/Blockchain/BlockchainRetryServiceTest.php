<?php

namespace Tests\Unit\Blockchain;

use App\Models\BlockchainJob;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockchainRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlockchainRetryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.max_retries' => 5,
            'blockchain.retry_base_seconds' => 10,
            'blockchain.private_key' => '0x'.str_repeat('d', 64),
        ]);

        $this->service = new BlockchainRetryService;
    }

    #[DataProvider('backoffDelayProvider')]
    public function test_exponential_backoff_delay_increases_across_failures(int $attemptNumber, int $expectedDelay): void
    {
        $this->assertSame($expectedDelay, $this->service->calculateDelaySeconds($attemptNumber));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function backoffDelayProvider(): array
    {
        return [
            'first failure' => [1, 10],
            'second failure' => [2, 20],
            'third failure' => [3, 40],
        ];
    }

    public function test_can_retry_until_max_attempts_is_reached(): void
    {
        $this->assertTrue($this->service->canRetry(1));
        $this->assertTrue($this->service->canRetry(4));
        $this->assertFalse($this->service->canRetry(5));
    }

    public function test_next_attempt_at_uses_exponential_backoff_from_frozen_time(): void
    {
        Carbon::setTestNow('2026-06-24 12:00:00');

        $nextAttempt = $this->service->nextAttemptAt(2);

        $this->assertSame('2026-06-24 12:00:20', $nextAttempt->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_sanitize_error_redacts_rpc_urls_private_keys_and_long_hex_secrets(): void
    {
        $message = implode(' ', [
            'Connection refused at http://127.0.0.1:7545',
            'key='.config('blockchain.private_key'),
            'hash=0x'.str_repeat('e', 64),
            'Authorization: Bearer abc.def.ghi',
        ]);

        $sanitized = $this->service->sanitizeError($message);

        $this->assertStringNotContainsString('http://127.0.0.1:7545', $sanitized);
        $this->assertStringNotContainsString((string) config('blockchain.private_key'), $sanitized);
        $this->assertStringNotContainsString('0x'.str_repeat('e', 64), $sanitized);
        $this->assertStringContainsString('[rpc-url-redacted]', $sanitized);
        $this->assertStringContainsString('[secret-redacted]', $sanitized);
        $this->assertStringContainsString('[token-redacted]', $sanitized);
    }

    public function test_invalid_config_values_are_normalized_safely(): void
    {
        config([
            'blockchain.max_retries' => 0,
            'blockchain.retry_base_seconds' => -5,
        ]);

        $this->assertSame(1, $this->service->maxAttempts());
        $this->assertSame(1, $this->service->retryBaseSeconds());
        $this->assertSame(1, $this->service->calculateDelaySeconds(1));
    }

    public function test_stale_retry_reason_detects_superseded_queued_job(): void
    {
        $record = BlockchainRecord::factory()->failed()->create([
            'retry_count' => 2,
        ]);

        $staleJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 3,
            'created_at' => now()->subMinute(),
        ]);

        BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 3,
            'created_at' => now(),
        ]);

        $this->assertSame(
            BlockchainRetryService::STALE_RETRY_REASON,
            $this->service->staleRetryReason($record, $staleJob)
        );
    }

    public function test_stale_retry_reason_returns_null_for_current_queued_job(): void
    {
        $record = BlockchainRecord::factory()->failed()->create([
            'retry_count' => 1,
        ]);

        $currentJob = BlockchainJob::factory()->for($record)->create([
            'job_type' => 'retry_anchor',
            'status' => 'queued',
            'attempts' => 2,
        ]);

        $this->assertNull($this->service->staleRetryReason($record, $currentJob));
    }
}

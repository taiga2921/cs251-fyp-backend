<?php

namespace App\Services;

/**
 * Aggregated outcome for one or more Web Push delivery attempts.
 */
final class WebPushDeliveryResult
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public int $attempted = 0,
        public int $succeeded = 0,
        public int $failed = 0,
        public int $expired = 0,
        public int $skippedInvalidPayload = 0,
        public int $skippedInvalidSubscription = 0,
        public array $failures = [],
    ) {}

    public function recordSuccess(): void
    {
        $this->attempted++;
        $this->succeeded++;
    }

    public function recordFailure(string $reason): void
    {
        $this->attempted++;
        $this->failed++;
        $this->failures[] = $reason;
    }

    public function recordExpired(): void
    {
        $this->attempted++;
        $this->expired++;
    }

    public function recordInvalidSubscription(): void
    {
        $this->skippedInvalidSubscription++;
    }

    public function recordInvalidPayload(): void
    {
        $this->skippedInvalidPayload++;
    }

    public function merge(self $other): self
    {
        $this->attempted += $other->attempted;
        $this->succeeded += $other->succeeded;
        $this->failed += $other->failed;
        $this->expired += $other->expired;
        $this->skippedInvalidPayload += $other->skippedInvalidPayload;
        $this->skippedInvalidSubscription += $other->skippedInvalidSubscription;
        $this->failures = array_merge($this->failures, $other->failures);

        return $this;
    }

    public function delivered(): bool
    {
        return $this->succeeded > 0;
    }

    public function hasAttemptedDelivery(): bool
    {
        return $this->attempted > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempted' => $this->attempted,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'expired' => $this->expired,
            'skipped_invalid_payload' => $this->skippedInvalidPayload,
            'skipped_invalid_subscription' => $this->skippedInvalidSubscription,
            'failures' => $this->failures,
        ];
    }
}

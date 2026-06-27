<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LoginRateLimiter
{
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @throws LoginRateLimitedException
     */
    public function ensureNotLocked(string $email, Request $request): void
    {
        if ($this->isLocked($email, $request)) {
            throw new LoginRateLimitedException($this->availableIn($email, $request));
        }
    }

    /**
     * Record a failed login attempt. Returns true when the attempt triggers lockout.
     */
    public function recordFailedAttempt(string $email, Request $request): bool
    {
        $key = $this->key($email, $request);
        $attemptsKey = $key.':attempts';
        $lockKey = $key.':lock';
        $maxAttempts = (int) config('auth_security.login_max_attempts', 5);
        $lockMinutes = (int) config('auth_security.login_lock_minutes', 15);

        $attempts = (int) Cache::get($attemptsKey, 0) + 1;
        Cache::put($attemptsKey, $attempts, now()->addMinutes($lockMinutes * 2));

        if ($attempts >= $maxAttempts) {
            Cache::put($lockKey, now()->addMinutes($lockMinutes)->timestamp, now()->addMinutes($lockMinutes));

            return true;
        }

        return false;
    }

    public function clear(string $email, Request $request): void
    {
        $key = $this->key($email, $request);
        Cache::forget($key.':attempts');
        Cache::forget($key.':lock');
    }

    public function availableIn(string $email, Request $request): int
    {
        $lockKey = $this->key($email, $request).':lock';
        $expiresAt = Cache::get($lockKey);

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) $expiresAt - now()->timestamp);
    }

    public function isLocked(string $email, Request $request): bool
    {
        $lockKey = $this->key($email, $request).':lock';
        $expiresAt = Cache::get($lockKey);

        if ($expiresAt === null) {
            return false;
        }

        if ((int) $expiresAt <= now()->timestamp) {
            Cache::forget($lockKey);

            return false;
        }

        return true;
    }

    private function key(string $email, Request $request): string
    {
        $material = $this->normalizeEmail($email).'|'.($request->ip() ?? 'unknown');

        return 'auth_login:'.hash('sha256', $material);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuthAuditService
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REVOKED = 'revoked';

    public const EVENT_LOGIN_PASSWORD_SUCCESS = 'login_password_success';

    public const EVENT_LOGIN_PASSWORD_FAILURE = 'login_password_failure';

    public const EVENT_LOGIN_RATE_LIMITED = 'login_rate_limited';

    public const EVENT_OTP_SUCCESS = 'otp_success';

    public const EVENT_OTP_FAILURE = 'otp_failure';

    public const EVENT_OTP_CHALLENGE_LOCKED = 'otp_challenge_locked';

    public const EVENT_PASSWORD_SETUP_COMPLETED = 'password_setup_completed';

    public const EVENT_TWO_FACTOR_SETUP_STARTED = 'two_factor_setup_started';

    public const EVENT_TWO_FACTOR_SETUP_COMPLETED = 'two_factor_setup_completed';

    public const EVENT_REFRESH_SUCCESS = 'refresh_success';

    public const EVENT_REFRESH_FAILURE = 'refresh_failure';

    public const EVENT_REFRESH_TOKEN_REUSE_DETECTED = 'refresh_token_reuse_detected';

    public const EVENT_LOGOUT_SUCCESS = 'logout_success';

    public const EVENT_LOGOUT_ALL_SUCCESS = 'logout_all_success';

    public const EVENT_SESSION_REVOKED = 'session_revoked';

    public const EVENT_REFRESH_BLOCKED_DISABLED_USER = 'refresh_blocked_disabled_user';

    public const EVENT_USER_DISABLED_SESSIONS_REVOKED = 'user_disabled_sessions_revoked';

    /** @var list<string> */
    private const EXACT_SENSITIVE_KEYS = [
        'authorization',
        'code',
        'cookie',
        'otp',
        'password',
        'secret',
        'totp',
    ];

    /** @var list<string> */
    private const SUBSTRING_SENSITIVE_KEYS = [
        'access_token',
        'auth_code',
        'manual_key',
        'one_time_code',
        'otp_code',
        'otpauth',
        'password_confirmation',
        'refresh_token',
        'setup_token',
        'token_hash',
        'totp_code',
        'two_factor_secret',
        'two_factor_setup_token',
        'token',
    ];

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $eventType,
        string $status,
        ?Request $request = null,
        ?User $user = null,
        ?string $email = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuthAuditLog {
        return AuthAuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'event_type' => $eventType,
            'status' => $status,
            'email' => $email ?? $user?->email,
            'ip_address' => $ipAddress ?? $request?->ip(),
            'user_agent' => $userAgent ?? $request?->userAgent(),
            'metadata' => $this->sanitizeMetadata($metadata),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveMetadataKey($normalizedKey)) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->sanitizeMetadata($value);
                if ($nested !== null && $nested !== []) {
                    $sanitized[$key] = $nested;
                }

                continue;
            }

            if (is_string($value) && $this->containsSensitiveValue($value)) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized === [] ? null : $sanitized;
    }

    private function isSensitiveMetadataKey(string $key): bool
    {
        if (in_array($key, self::EXACT_SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SUBSTRING_SENSITIVE_KEYS as $forbidden) {
            if ($key === $forbidden || str_contains($key, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function containsSensitiveValue(string $value): bool
    {
        if (strlen($value) >= 64 && ctype_xdigit($value)) {
            return true;
        }

        return str_starts_with($value, 'Bearer ')
            || str_starts_with($value, 'otpauth://');
    }
}

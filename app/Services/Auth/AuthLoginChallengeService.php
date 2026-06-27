<?php

namespace App\Services\Auth;

use App\Models\AuthLoginChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthLoginChallengeService
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    public function createForUser(User $user, ?Request $request = null): AuthLoginChallenge
    {
        return DB::transaction(function () use ($user, $request) {
            AuthLoginChallenge::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->whereNull('locked_at')
                ->delete();

            $ttlMinutes = (int) config('auth_security.otp_challenge_ttl_minutes', 5);

            return AuthLoginChallenge::query()->create([
                'user_id' => $user->getKey(),
                'expires_at' => now()->addMinutes($ttlMinutes),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        });
    }

    /**
     * @throws InvalidOtpChallengeException
     */
    public function verify(string $challengeId, string $otp): User
    {
        $challenge = AuthLoginChallenge::query()->whereKey($challengeId)->first();

        if ($challenge === null || $challenge->isConsumed() || $challenge->isLocked() || $challenge->isExpired()) {
            throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
        }

        $user = User::query()->find($challenge->user_id);

        if ($user === null || ! $user->two_factor_enabled || $user->two_factor_secret === null) {
            throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
        }

        if (! $this->twoFactorService->verifyForUser($user, $otp)) {
            $challengeLocked = false;

            DB::transaction(function () use ($challengeId, &$challengeLocked) {
                $lockedChallenge = AuthLoginChallenge::query()
                    ->whereKey($challengeId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedChallenge === null) {
                    throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
                }

                if ($lockedChallenge->isConsumed() || $lockedChallenge->isLocked() || $lockedChallenge->isExpired()) {
                    return;
                }

                $failedAttempts = $lockedChallenge->failed_attempts + 1;
                $maxAttempts = (int) config('auth_security.otp_max_attempts', 5);
                $challengeLocked = $failedAttempts >= $maxAttempts;

                $lockedChallenge->forceFill([
                    'failed_attempts' => $failedAttempts,
                    'locked_at' => $challengeLocked ? now() : null,
                ])->save();
            });

            $this->authAuditService->record(
                'otp_failed',
                user: $user,
                metadata: ['login_challenge_id' => $challengeId],
                ipAddress: $challenge->ip_address,
                userAgent: $challenge->user_agent,
            );

            if ($challengeLocked) {
                $this->authAuditService->record(
                    'otp_challenge_locked',
                    user: $user,
                    metadata: ['login_challenge_id' => $challengeId],
                    ipAddress: $challenge->ip_address,
                    userAgent: $challenge->user_agent,
                );
            }

            throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
        }

        return DB::transaction(function () use ($challengeId) {
            $lockedChallenge = AuthLoginChallenge::query()
                ->whereKey($challengeId)
                ->lockForUpdate()
                ->first();

            if ($lockedChallenge === null) {
                throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
            }

            if ($lockedChallenge->isConsumed() || $lockedChallenge->isLocked() || $lockedChallenge->isExpired()) {
                throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
            }

            $user = User::query()->lockForUpdate()->find($lockedChallenge->user_id);

            if ($user === null || ! $user->two_factor_enabled || $user->two_factor_secret === null) {
                throw new InvalidOtpChallengeException('The authentication code is invalid or expired.');
            }

            $lockedChallenge->markConsumed();

            return $user->fresh(['role']);
        });
    }
}

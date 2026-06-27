<?php

namespace App\Services\Auth;

use App\Models\TwoFactorSetupSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TwoFactorSetupService
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    /**
     * @return array{model: TwoFactorSetupSession, plain_token: string}
     */
    public function createForUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            TwoFactorSetupSession::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('verified_at')
                ->delete();

            $plainToken = bin2hex(random_bytes(32));
            $ttlMinutes = (int) config('auth_security.two_factor_setup_ttl_minutes', 10);

            $model = TwoFactorSetupSession::query()->create([
                'user_id' => $lockedUser->getKey(),
                'token_hash' => $this->hashPlainToken($plainToken),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            return [
                'model' => $model,
                'plain_token' => $plainToken,
            ];
        });
    }

    /**
     * @return array{manual_key: string, otpauth_uri: string, expires_in: int}
     *
     * @throws InvalidTwoFactorSetupTokenException
     */
    public function startSetup(string $plainToken, ?Request $request = null): array
    {
        return DB::transaction(function () use ($plainToken, $request) {
            $session = $this->lockActiveSession($plainToken);
            $user = User::query()->lockForUpdate()->findOrFail($session->user_id);

            if ($user->two_factor_enabled) {
                throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
            }

            $plainSecret = $this->twoFactorService->generateSecret();
            $session->forceFill([
                'pending_secret' => $this->twoFactorService->encryptSecret($plainSecret),
            ])->save();

            $this->authAuditService->record(
                AuthAuditService::EVENT_TWO_FACTOR_SETUP_STARTED,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $user,
            );

            return [
                'manual_key' => $plainSecret,
                'otpauth_uri' => $this->twoFactorService->buildOtpauthUri($user, $plainSecret),
                'expires_in' => max(0, $session->expires_at?->diffInSeconds(now()) ?? 0),
            ];
        });
    }

    /**
     * @throws InvalidTwoFactorSetupTokenException
     */
    public function verifySetup(string $plainToken, string $otp): User
    {
        $session = TwoFactorSetupSession::query()
            ->where('token_hash', $this->hashPlainToken($plainToken))
            ->first();

        if ($session === null || $session->isExpired() || $session->isVerified() || $session->isLocked()) {
            throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
        }

        $user = User::query()->find($session->user_id);

        if ($user === null || $user->two_factor_enabled || $session->pending_secret === null) {
            throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
        }

        $plainSecret = $this->twoFactorService->decryptSecret($session->pending_secret);

        if (! $this->twoFactorService->verifyCode($plainSecret, $otp)) {
            DB::transaction(function () use ($plainToken) {
                $lockedSession = TwoFactorSetupSession::query()
                    ->where('token_hash', $this->hashPlainToken($plainToken))
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedSession->isExpired() || $lockedSession->isVerified() || $lockedSession->isLocked()) {
                    return;
                }

                $failedAttempts = $lockedSession->failed_attempts + 1;
                $maxAttempts = (int) config('auth_security.otp_max_attempts', 5);

                $lockedSession->forceFill([
                    'failed_attempts' => $failedAttempts,
                    'locked_at' => $failedAttempts >= $maxAttempts ? now() : null,
                ])->save();
            });

            throw new InvalidTwoFactorSetupTokenException('The provided authentication code is invalid.');
        }

        return DB::transaction(function () use ($plainToken, $plainSecret) {
            $lockedSession = TwoFactorSetupSession::query()
                ->where('token_hash', $this->hashPlainToken($plainToken))
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSession->isExpired() || $lockedSession->isVerified() || $lockedSession->isLocked()) {
                throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
            }

            if ($lockedSession->pending_secret === null) {
                throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
            }

            $user = User::query()->lockForUpdate()->findOrFail($lockedSession->user_id);
            $this->twoFactorService->enableTwoFactorForUser($user, $plainSecret);

            $lockedSession->forceFill([
                'verified_at' => now(),
                'pending_secret' => null,
            ])->save();

            return $user->fresh(['role']);
        });
    }

    /**
     * @throws InvalidTwoFactorSetupTokenException
     */
    private function lockActiveSession(string $plainToken): TwoFactorSetupSession
    {
        $session = TwoFactorSetupSession::query()
            ->where('token_hash', $this->hashPlainToken($plainToken))
            ->lockForUpdate()
            ->first();

        if ($session === null || $session->isExpired() || $session->isVerified() || $session->isLocked()) {
            throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
        }

        return $session;
    }

    private function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}

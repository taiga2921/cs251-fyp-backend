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
    public function startSetup(string $plainToken): array
    {
        return DB::transaction(function () use ($plainToken) {
            $session = $this->lockActiveSession($plainToken);
            $user = User::query()->lockForUpdate()->findOrFail($session->user_id);

            if ($user->two_factor_enabled) {
                throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
            }

            $plainSecret = $this->twoFactorService->generateSecret();
            $session->forceFill([
                'pending_secret' => $this->twoFactorService->encryptSecret($plainSecret),
            ])->save();

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
        return DB::transaction(function () use ($plainToken, $otp) {
            $session = $this->lockActiveSession($plainToken);
            $user = User::query()->lockForUpdate()->findOrFail($session->user_id);

            if ($session->pending_secret === null) {
                throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
            }

            $plainSecret = $this->twoFactorService->decryptSecret($session->pending_secret);

            if (! $this->twoFactorService->verifyCode($plainSecret, $otp)) {
                throw new InvalidTwoFactorSetupTokenException('The provided authentication code is invalid.');
            }

            $this->twoFactorService->enableTwoFactorForUser($user, $plainSecret);

            $session->forceFill([
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

        if ($session === null || $session->isExpired() || $session->isVerified()) {
            throw new InvalidTwoFactorSetupTokenException('Two-factor setup token is invalid or expired.');
        }

        return $session;
    }

    private function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}

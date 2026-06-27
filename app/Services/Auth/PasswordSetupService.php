<?php

namespace App\Services\Auth;

use App\Models\PasswordSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordSetupService
{
    /**
     * @return array{model: PasswordSetupToken, plain_token: string}
     */
    public function createForUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            PasswordSetupToken::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('used_at')
                ->delete();

            $plainToken = bin2hex(random_bytes(32));
            $ttlHours = (int) config('auth_security.password_setup_token_ttl_hours', 24);

            $model = PasswordSetupToken::query()->create([
                'user_id' => $lockedUser->getKey(),
                'token_hash' => $this->hashPlainToken($plainToken),
                'expires_at' => now()->addHours($ttlHours),
            ]);

            return [
                'model' => $model,
                'plain_token' => $plainToken,
            ];
        });
    }

    public function findByPlainToken(string $plainToken): ?PasswordSetupToken
    {
        if ($plainToken === '') {
            return null;
        }

        return PasswordSetupToken::query()
            ->where('token_hash', $this->hashPlainToken($plainToken))
            ->first();
    }

    /**
     * @throws InvalidPasswordSetupTokenException
     */
    public function validatePlainToken(string $plainToken): PasswordSetupToken
    {
        $token = $this->findByPlainToken($plainToken);

        if ($token === null) {
            throw new InvalidPasswordSetupTokenException('Password setup token is invalid or expired.');
        }

        if ($token->isUsed()) {
            throw new InvalidPasswordSetupTokenException('Password setup token is invalid or expired.');
        }

        if ($token->isExpired()) {
            throw new InvalidPasswordSetupTokenException('Password setup token is invalid or expired.');
        }

        return $token;
    }

    /**
     * @throws InvalidPasswordSetupTokenException
     */
    public function completeSetup(string $plainToken, string $newPassword): User
    {
        return DB::transaction(function () use ($plainToken, $newPassword) {
            $token = PasswordSetupToken::query()
                ->where('token_hash', $this->hashPlainToken($plainToken))
                ->lockForUpdate()
                ->first();

            if ($token === null || $token->isUsed() || $token->isExpired()) {
                throw new InvalidPasswordSetupTokenException('Password setup token is invalid or expired.');
            }

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($token->user_id);

            $user->forceFill([
                'password' => Hash::make($newPassword),
                'setup_required' => false,
                'last_password_changed_at' => now(),
            ])->save();

            $token->markUsed();

            return $user->fresh(['role']);
        });
    }

    private function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}

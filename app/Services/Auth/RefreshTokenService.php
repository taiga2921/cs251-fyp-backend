<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class RefreshTokenService
{
    /**
     * @return array{model: RefreshToken, plain_token: string}
     */
    public function createForUser(User $user, ?Request $request = null): array
    {
        return $this->createTokenForUser($user, $request, (string) Str::uuid());
    }

    public function findByPlainToken(string $plainToken): ?RefreshToken
    {
        if ($plainToken === '') {
            return null;
        }

        return RefreshToken::query()
            ->where('token_hash', $this->hashPlainToken($plainToken))
            ->first();
    }

    /**
     * @throws InvalidRefreshTokenException
     * @throws RefreshTokenReuseException
     */
    public function validatePlainToken(string $plainToken): RefreshToken
    {
        $token = $this->findByPlainToken($plainToken);

        if ($token === null) {
            throw new InvalidRefreshTokenException('Refresh token not found.');
        }

        return $this->assertTokenUsable($token);
    }

    /**
     * @return array{model: RefreshToken, plain_token: string}
     *
     * @throws InvalidRefreshTokenException
     * @throws RefreshTokenReuseException
     */
    public function rotatePlainToken(string $plainToken, ?Request $request = null): array
    {
        $tokenHash = $this->hashPlainToken($plainToken);

        try {
            return DB::transaction(function () use ($tokenHash, $request) {
                $token = RefreshToken::query()
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if ($token === null) {
                    throw new InvalidRefreshTokenException('Refresh token not found.');
                }

                if ($token->rotated_at !== null) {
                    throw new RefreshTokenReuseException('Rotated refresh token reuse detected.');
                }

                if ($token->revoked_at !== null || $token->expires_at->isPast()) {
                    throw new InvalidRefreshTokenException('Refresh token is no longer valid.');
                }

                $token->forceFill([
                    'rotated_at' => now(),
                    'last_used_at' => now(),
                ])->save();

                return $this->createTokenForUser(
                    $token->user()->firstOrFail(),
                    $request,
                    $token->token_family
                );
            });
        } catch (RefreshTokenReuseException $exception) {
            $token = RefreshToken::query()->where('token_hash', $tokenHash)->first();

            if ($token !== null) {
                $this->revokeFamily($token->token_family);
            }

            throw $exception;
        }
    }

    public function revokeFromPlainToken(?string $plainToken): void
    {
        if ($plainToken === null || $plainToken === '') {
            return;
        }

        $token = $this->findByPlainToken($plainToken);

        if ($token !== null && ! $token->isRevoked()) {
            $token->revoke();
        }
    }

    public function revoke(RefreshToken $token): void
    {
        $token->revoke();
    }

    public function revokeFamily(string $tokenFamily): void
    {
        RefreshToken::query()
            ->where('token_family', $tokenFamily)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(User $user): int
    {
        return RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function makeCookie(string $plainToken): Cookie
    {
        $name = (string) config('auth_security.refresh_cookie_name');
        $ttlHours = (int) config('auth_security.refresh_token_ttl_hours', 12);
        $maxAge = max($ttlHours * 3600, 60);

        return Cookie::create($name)
            ->withValue($plainToken)
            ->withExpires(time() + $maxAge)
            ->withPath((string) config('auth_security.refresh_cookie_path', '/api/auth'))
            ->withSecure((bool) config('auth_security.refresh_cookie_secure', false))
            ->withHttpOnly(true)
            ->withSameSite($this->resolveSameSite());
    }

    public function forgetCookie(): Cookie
    {
        $name = (string) config('auth_security.refresh_cookie_name');

        return Cookie::create($name)
            ->withValue('')
            ->withExpires(time() - 3600)
            ->withPath((string) config('auth_security.refresh_cookie_path', '/api/auth'))
            ->withSecure((bool) config('auth_security.refresh_cookie_secure', false))
            ->withHttpOnly(true)
            ->withSameSite($this->resolveSameSite());
    }

    public function readPlainTokenFromRequest(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $plainToken = $request->cookie((string) config('auth_security.refresh_cookie_name'));

        return is_string($plainToken) && $plainToken !== '' ? $plainToken : null;
    }

    /**
     * @throws InvalidRefreshTokenException
     * @throws RefreshTokenReuseException
     */
    private function assertTokenUsable(RefreshToken $token): RefreshToken
    {
        if ($token->isRotated()) {
            $this->revokeFamily($token->token_family);

            throw new RefreshTokenReuseException('Rotated refresh token reuse detected.');
        }

        if ($token->isRevoked() || $token->isExpired()) {
            throw new InvalidRefreshTokenException('Refresh token is no longer valid.');
        }

        return $token;
    }

    /**
     * @return array{model: RefreshToken, plain_token: string}
     */
    private function createTokenForUser(User $user, ?Request $request, string $tokenFamily): array
    {
        $plainToken = bin2hex(random_bytes(32));
        $ttlHours = (int) config('auth_security.refresh_token_ttl_hours', 12);

        $model = RefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hashPlainToken($plainToken),
            'token_family' => $tokenFamily,
            'device_name' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'expires_at' => now()->addHours($ttlHours),
        ]);

        return [
            'model' => $model,
            'plain_token' => $plainToken,
        ];
    }

    private function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function resolveSameSite(): string
    {
        $sameSite = strtolower((string) config('auth_security.refresh_cookie_same_site', 'lax'));

        return match ($sameSite) {
            'strict', 'none' => $sameSite,
            default => Cookie::SAMESITE_LAX,
        };
    }
}

<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Auth\TwoFactorService;

trait EnablesTwoFactorAuth
{
    protected string $testTotpSecret = 'JBSWY3DPEHPK3PXP';

    protected function enableTwoFactor(User $user, ?string $secret = null): User
    {
        $secret = $secret ?? $this->testTotpSecret;
        app(TwoFactorService::class)->enableTwoFactorForUser($user, $secret);

        return $user->fresh(['role']);
    }

    protected function currentTotp(?string $secret = null, ?int $timestamp = null): string
    {
        return app(TwoFactorService::class)->generateTotp($secret ?? $this->testTotpSecret, $timestamp);
    }

    /**
     * @return array{login: \Illuminate\Testing\TestResponse, verify: \Illuminate\Testing\TestResponse}
     */
    protected function loginWithOtp(User $user, string $password = 'password'): array
    {
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $verifyResponse = $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $loginResponse->json('data.login_challenge_id'),
            'otp' => $this->currentTotp(),
        ]);

        return [
            'login' => $loginResponse,
            'verify' => $verifyResponse,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AuthRefreshTokenTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config([
            'jwt.secret' => 'test-jwt-secret-key-for-auth-tests-32chars',
            'auth_security.access_token_ttl_minutes' => 30,
            'auth_security.refresh_cookie_name' => 'refresh_token',
            'auth_security.refresh_cookie_path' => '/api/auth',
        ]);
    }

    public function test_successful_login_returns_access_token_and_sets_refresh_cookie(): void
    {
        $user = $this->guardUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'expires_in',
                    'user' => ['id', 'email'],
                    'role',
                ],
            ])
            ->assertCookie('refresh_token');

        $this->assertArrayNotHasKey('refresh_token', $response->json('data'));

        $this->assertSame(1800, $response->json('data.expires_in'));
        $this->assertDatabaseCount('refresh_tokens', 1);

        $cookie = $this->extractRefreshCookie($response);
        $this->assertNotSame($cookie->getValue(), RefreshToken::query()->value('token_hash'));
    }

    public function test_valid_refresh_cookie_returns_new_access_token_and_rotates_cookie(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $originalCookie = $this->extractRefreshCookie($loginResponse);
        $originalTokenId = RefreshToken::query()->value('id');

        $refreshResponse = $this->withUnencryptedCookie($originalCookie->getName(), $originalCookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh');

        $refreshResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user', 'role']])
            ->assertCookie('refresh_token');

        $newCookie = $this->extractRefreshCookie($refreshResponse);
        $this->assertNotSame($originalCookie->getValue(), $newCookie->getValue());
        $this->assertNotSame($loginResponse->json('data.access_token'), $refreshResponse->json('data.access_token'));

        $originalToken = RefreshToken::query()->findOrFail($originalTokenId);
        $this->assertNotNull($originalToken->rotated_at);
        $this->assertDatabaseCount('refresh_tokens', 2);
    }

    public function test_refresh_without_cookie_returns_unauthorized_and_clears_cookie(): void
    {
        $this->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Refresh session is invalid or expired.')
            ->assertCookieExpired('refresh_token');
    }

    public function test_refresh_with_expired_token_returns_unauthorized(): void
    {
        Carbon::setTestNow('2026-06-27 10:00:00');

        $created = app(\App\Services\Auth\RefreshTokenService::class)->createForUser($this->guardUser());

        Carbon::setTestNow('2026-06-28 10:00:00');

        $this->withUnencryptedCookie($this->refreshCookieName(), $created['plain_token'])
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Refresh session is invalid or expired.');

        Carbon::setTestNow();
    }

    public function test_refresh_with_revoked_token_returns_unauthorized(): void
    {
        $created = app(\App\Services\Auth\RefreshTokenService::class)->createForUser($this->guardUser());
        $created['model']->revoke();

        $this->withUnencryptedCookie($this->refreshCookieName(), $created['plain_token'])
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_reusing_rotated_refresh_token_revokes_family(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $originalCookie = $this->extractRefreshCookie($loginResponse);
        $family = RefreshToken::query()->value('token_family');

        $this->withUnencryptedCookie($originalCookie->getName(), $originalCookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertOk();

        $this->withUnencryptedCookie($originalCookie->getName(), $originalCookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();

        $this->assertSame(
            RefreshToken::query()->where('token_family', $family)->count(),
            RefreshToken::query()->where('token_family', $family)->whereNotNull('revoked_at')->count()
        );
    }

    public function test_logout_revokes_refresh_token_and_clears_cookie(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $accessToken = $loginResponse->json('data.access_token');
        $refreshCookie = $this->extractRefreshCookie($loginResponse);
        $refreshTokenId = RefreshToken::query()->value('id');

        $logoutResponse = $this->withUnencryptedCookies([
            $refreshCookie->getName() => $refreshCookie->getValue(),
        ])
            ->withCredentials()
            ->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertCookieExpired('refresh_token');

        $this->assertNotNull(RefreshToken::query()->find($refreshTokenId)?->revoked_at);
    }

    public function test_logout_revokes_refresh_session_without_bearer_token(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $refreshCookie = $this->extractRefreshCookie($loginResponse);
        $refreshTokenId = RefreshToken::query()->value('id');

        $this->withUnencryptedCookies([
            $refreshCookie->getName() => $refreshCookie->getValue(),
        ])
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertCookieExpired('refresh_token');

        $this->assertNotNull(RefreshToken::query()->find($refreshTokenId)?->revoked_at);
    }

    public function test_logout_revokes_refresh_session_when_bearer_token_is_expired_or_invalid(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $refreshCookie = $this->extractRefreshCookie($loginResponse);
        $refreshTokenId = RefreshToken::query()->value('id');

        $this->withUnencryptedCookies([
            $refreshCookie->getName() => $refreshCookie->getValue(),
        ])
            ->withCredentials()
            ->withHeader('Authorization', 'Bearer invalid.jwt.token')
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertCookieExpired('refresh_token');

        $this->assertNotNull(RefreshToken::query()->find($refreshTokenId)?->revoked_at);
    }

    public function test_logout_without_refresh_cookie_is_tolerant(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.')
            ->assertCookieExpired('refresh_token');
    }

    public function test_refresh_after_logout_fails_with_unauthorized(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $refreshCookie = $this->extractRefreshCookie($loginResponse);

        $this->withUnencryptedCookies([
            $refreshCookie->getName() => $refreshCookie->getValue(),
        ])
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withUnencryptedCookie($refreshCookie->getName(), $refreshCookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_invalid_login_credentials_are_unchanged(): void
    {
        $user = $this->guardUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');

        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_custom_refresh_cookie_name_supports_login_refresh_and_logout(): void
    {
        config(['auth_security.refresh_cookie_name' => 'ikh_refresh_token']);

        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse->assertOk()
            ->assertCookie('ikh_refresh_token');

        $this->assertArrayNotHasKey('ikh_refresh_token', $loginResponse->json('data'));
        $this->assertArrayNotHasKey('refresh_token', $loginResponse->json('data'));

        $cookie = $this->extractRefreshCookie($loginResponse);

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertCookie('ikh_refresh_token');

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired('ikh_refresh_token');
    }

    public function test_refresh_rejects_setup_required_user_and_revokes_session(): void
    {
        $user = $this->guardUser();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $cookie = $this->extractRefreshCookie($loginResponse);
        $refreshTokenId = RefreshToken::query()->value('id');

        $user->forceFill(['setup_required' => true])->save();

        $refreshResponse = $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh');

        $refreshResponse->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Refresh session is invalid or expired.')
            ->assertCookieExpired($this->refreshCookieName());

        $this->assertNull($refreshResponse->json('data.access_token'));

        $refreshToken = RefreshToken::query()->find($refreshTokenId);
        $this->assertNotNull($refreshToken?->revoked_at);
    }

    private function refreshCookieName(): string
    {
        return (string) config('auth_security.refresh_cookie_name', 'refresh_token');
    }

    /**
     * @param  \Illuminate\Testing\TestResponse  $response
     */
    private function extractRefreshCookie($response): Cookie
    {
        $cookie = $response->getCookie($this->refreshCookieName(), decrypt: false);
        $this->assertNotNull($cookie);

        return $cookie;
    }
}

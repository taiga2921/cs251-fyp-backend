<?php

namespace Tests\Feature;

use App\Models\AuthAuditLog;
use App\Models\RefreshToken;
use App\Services\Auth\AuthAuditService;
use App\Services\Auth\RefreshTokenService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthSessionMonitoringTest extends TestCase
{
    use CreatesPatrolUsers;
    use EnablesTwoFactorAuth;
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

    public function test_login_creates_refresh_session_visible_in_sessions_list(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $token = $login->json('data.access_token');
        $cookie = $login->getCookie('refresh_token', false);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withUnencryptedCookie('refresh_token', $cookie->getValue())
            ->withCredentials()
            ->getJson('/api/auth/sessions');

        $response->assertOk()
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonMissingPath('data.0.token_hash');
    }

    public function test_session_list_never_exposes_token_hash_or_raw_token(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $token = $login->json('data.access_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/sessions');

        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('token_hash', $encoded);
        $this->assertSame(1, RefreshToken::query()->count());
        $this->assertStringNotContainsString(RefreshToken::query()->value('token_hash'), $encoded);
    }

    public function test_user_can_list_own_sessions(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $token = $this->loginWithOtp($user)['verify']->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $user->getKey());
    }

    public function test_admin_can_list_all_sessions(): void
    {
        $admin = $this->adminUser();
        $guard = $this->enableTwoFactor($this->guardUser());
        $this->loginWithOtp($guard)['verify'];

        $this->actingAs($admin, 'api')
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_revoke_another_users_session(): void
    {
        $guardA = $this->enableTwoFactor($this->guardUser());
        $guardB = $this->enableTwoFactor($this->securityOperatorUser());

        $this->loginWithOtp($guardA);
        $sessionId = RefreshToken::query()->where('user_id', $guardA->getKey())->value('id');

        $tokenB = $this->loginWithOtp($guardB)['verify']->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->deleteJson('/api/auth/sessions/'.$sessionId)
            ->assertForbidden();
    }

    public function test_admin_can_revoke_another_users_session(): void
    {
        $admin = $this->adminUser();
        $guard = $this->enableTwoFactor($this->guardUser());
        $this->loginWithOtp($guard)['verify'];
        $sessionId = RefreshToken::query()->where('user_id', $guard->getKey())->value('id');

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/auth/sessions/'.$sessionId)
            ->assertOk();

        $this->assertNotNull(RefreshToken::query()->find($sessionId)->revoked_at);
    }

    public function test_revoked_session_cannot_refresh(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $cookie = $login->getCookie('refresh_token', false);
        $sessionId = RefreshToken::query()->where('user_id', $user->getKey())->value('id');
        $token = $login->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/auth/sessions/'.$sessionId)
            ->assertOk();

        $this->withUnencryptedCookie('refresh_token', $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_logout_all_revokes_refresh_sessions_and_clears_cookie(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $token = $login->json('data.access_token');
        $cookie = $login->getCookie('refresh_token', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withUnencryptedCookie('refresh_token', $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/logout-all')
            ->assertOk()
            ->assertCookieExpired('refresh_token');

        $this->assertSame(0, RefreshToken::query()->active()->count());
    }

    public function test_session_revoke_writes_audit_event(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $token = $login->json('data.access_token');
        $sessionId = RefreshToken::query()->where('user_id', $user->getKey())->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/auth/sessions/'.$sessionId)
            ->assertOk();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_SESSION_REVOKED,
            'status' => AuthAuditService::STATUS_REVOKED,
            'user_id' => $user->getKey(),
        ]);
    }
}

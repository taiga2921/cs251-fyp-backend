<?php

namespace Tests\Feature;

use App\Models\AuthLoginChallenge;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthTwoFactorTest extends TestCase
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
            'auth_security.password_min_length' => 12,
            'auth_security.otp_challenge_ttl_minutes' => 5,
            'auth_security.otp_max_attempts' => 5,
            'auth_security.two_factor_setup_ttl_minutes' => 10,
        ]);
    }

    public function test_password_setup_completion_returns_two_factor_setup_token(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);

        $setupToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ])->json('data.setup_token');

        $response = $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_required')
            ->assertJsonStructure(['data' => ['two_factor_setup_token', 'expires_in', 'user']])
            ->assertCookieMissing('refresh_token');

        $this->assertNull($response->json('data.access_token'));
    }

    public function test_two_factor_setup_start_returns_manual_key_and_otpauth_uri_without_tokens(): void
    {
        $token = $this->createTwoFactorSetupToken();

        $response = $this->postJson('/api/auth/2fa/setup/start', [
            'two_factor_setup_token' => $token,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_verify_required')
            ->assertJsonStructure(['data' => ['manual_key', 'otpauth_uri', 'expires_in']])
            ->assertCookieMissing('refresh_token');

        $this->assertStringStartsWith('otpauth://totp/', $response->json('data.otpauth_uri'));
        $this->assertNotEmpty($response->json('data.manual_key'));
    }

    public function test_two_factor_setup_verify_with_invalid_otp_does_not_enable_two_factor(): void
    {
        $token = $this->createTwoFactorSetupToken();

        $this->postJson('/api/auth/2fa/setup/start', [
            'two_factor_setup_token' => $token,
        ])->assertOk();

        $user = User::query()->where('email', 'newguard@example.com')->firstOrFail();

        $this->postJson('/api/auth/2fa/setup/verify', [
            'two_factor_setup_token' => $token,
            'otp' => '000000',
        ])->assertStatus(422);

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_two_factor_setup_verify_with_valid_otp_issues_session(): void
    {
        $token = $this->createTwoFactorSetupToken();

        $start = $this->postJson('/api/auth/2fa/setup/start', [
            'two_factor_setup_token' => $token,
        ])->assertOk();

        $manualKey = $start->json('data.manual_key');
        $otp = app(TwoFactorService::class)->generateTotp($manualKey);

        $response = $this->postJson('/api/auth/2fa/setup/verify', [
            'two_factor_setup_token' => $token,
            'otp' => $otp,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'user', 'role']])
            ->assertCookie('refresh_token');

        $user = User::query()->where('email', 'newguard@example.com')->firstOrFail();
        $this->assertTrue($user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotSame($manualKey, $user->two_factor_secret);
    }

    public function test_login_for_two_factor_enabled_user_returns_otp_required(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'otp_required')
            ->assertJsonStructure(['data' => ['login_challenge_id', 'expires_in']])
            ->assertCookieMissing('refresh_token');

        $this->assertNull($response->json('data.access_token'));
    }

    public function test_otp_verify_with_valid_code_issues_access_token_and_refresh_cookie(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $verify = $this->loginWithOtp($user)['verify'];

        $verify->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'user', 'role']])
            ->assertCookie('refresh_token');
    }

    public function test_otp_verify_with_wrong_code_increments_failed_attempts(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => '000000',
        ])->assertStatus(422);

        $challenge = AuthLoginChallenge::query()->findOrFail($challengeId);
        $this->assertSame(1, $challenge->failed_attempts);
    }

    public function test_otp_verify_locks_challenge_after_max_failed_attempts(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', [
                'login_challenge_id' => $challengeId,
                'otp' => '000000',
            ]);
        }

        $challenge = AuthLoginChallenge::query()->findOrFail($challengeId);
        $this->assertNotNull($challenge->locked_at);

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])->assertStatus(422);
    }

    public function test_expired_otp_challenge_fails(): void
    {
        Carbon::setTestNow('2026-06-27 10:00:00');
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        Carbon::setTestNow('2026-06-27 10:10:00');

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_used_otp_challenge_cannot_be_reused(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])->assertOk();

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])->assertStatus(422);
    }

    public function test_refresh_fails_for_user_without_completed_two_factor(): void
    {
        $user = $this->guardUser();
        $created = app(RefreshTokenService::class)->createForUser($user);

        $this->withUnencryptedCookie('refresh_token', $created['plain_token'])
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertCookieExpired('refresh_token');
    }

    public function test_login_without_two_factor_enabled_returns_setup_required(): void
    {
        $user = $this->guardUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_required');
    }

    private function createTwoFactorSetupToken(): string
    {
        $admin = $this->adminUser();
        $roleId = Role::query()->where('name', 'Guard')->value('id');

        $this->actingAs($admin, 'api')
            ->postJson('/api/users', [
                'name' => 'New Guard',
                'email' => 'newguard@example.com',
                'password' => 'TempPassword1!',
                'role_id' => $roleId,
            ])
            ->assertCreated();

        $passwordSetupToken = $this->postJson('/api/auth/login', [
            'email' => 'newguard@example.com',
            'password' => 'TempPassword1!',
        ])->json('data.setup_token');

        return $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $passwordSetupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ])->json('data.two_factor_setup_token');
    }
}

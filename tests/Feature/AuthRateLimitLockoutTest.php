<?php

namespace Tests\Feature;

use App\Models\AuthAuditLog;
use App\Models\AuthLoginChallenge;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthRateLimitLockoutTest extends TestCase
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
            'auth_security.login_max_attempts' => 5,
            'auth_security.login_lock_minutes' => 15,
            'auth_security.otp_challenge_ttl_minutes' => 5,
            'auth_security.otp_max_attempts' => 5,
        ]);
    }

    public function test_failed_login_attempts_are_counted_by_email_and_ip(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], $this->serverVariables('203.0.113.10'))
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Invalid credentials.');
        }

        $this->assertDatabaseCount('auth_audit_logs', 3);
        $this->assertSame(3, AuthAuditLog::query()->where('event_type', 'login_failed')->count());
    }

    public function test_five_failed_login_attempts_trigger_lockout(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], $this->serverVariables('203.0.113.11'))
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ], $this->serverVariables('203.0.113.11'))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many unsuccessful sign-in attempts. Please try again later.')
            ->assertJsonStructure(['data' => ['retry_after']]);
    }

    public function test_locked_login_returns_safe_generic_error_without_account_enumeration(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $this->lockLoginFor($user->email, '203.0.113.12');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.12'))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many unsuccessful sign-in attempts. Please try again later.')
            ->assertJsonMissingPath('data.next_step')
            ->assertCookieMissing('refresh_token');

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password',
        ], $this->serverVariables('203.0.113.12'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_threshold_failed_login_writes_login_failed_and_rate_limited_audit(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $ip = '203.0.113.15';

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], $this->serverVariables($ip))
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ], $this->serverVariables($ip))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many unsuccessful sign-in attempts. Please try again later.');

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => 'login_failed',
            'email' => strtolower($user->email),
            'ip_address' => $ip,
        ]);
        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => 'login_rate_limited',
            'email' => strtolower($user->email),
            'ip_address' => $ip,
        ]);
    }

    public function test_locked_login_writes_login_rate_limited_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $this->lockLoginFor($user->email, '203.0.113.13');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.13'))
            ->assertStatus(429);

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => 'login_rate_limited',
            'email' => strtolower($user->email),
        ]);
    }

    public function test_successful_credential_validation_clears_previous_failed_login_attempts(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], $this->serverVariables('203.0.113.14'))
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.14'))
            ->assertOk()
            ->assertJsonPath('data.next_step', 'otp_required');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], $this->serverVariables('203.0.113.14'))
                ->assertUnauthorized();
        }
    }

    public function test_rate_limit_is_scoped_by_email_and_ip(): void
    {
        $userA = $this->enableTwoFactor($this->guardUser());
        $userB = $this->enableTwoFactor($this->securityOperatorUser());

        $this->lockLoginFor($userA->email, '203.0.113.20');

        $this->postJson('/api/auth/login', [
            'email' => $userA->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.21'))
            ->assertOk()
            ->assertJsonPath('data.next_step', 'otp_required');

        $this->postJson('/api/auth/login', [
            'email' => $userB->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.20'))
            ->assertOk()
            ->assertJsonPath('data.next_step', 'otp_required');
    }

    public function test_correct_password_while_locked_remains_blocked_until_lock_expires(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        config(['auth_security.login_lock_minutes' => 1]);

        $user = $this->enableTwoFactor($this->guardUser());
        $this->lockLoginFor($user->email, '203.0.113.30');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.30'))
            ->assertStatus(429);

        Carbon::setTestNow('2026-07-01 10:01:01');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->serverVariables('203.0.113.30'))
            ->assertOk()
            ->assertJsonPath('data.next_step', 'otp_required');

        Carbon::setTestNow();
    }

    public function test_otp_wrong_code_increments_failed_attempts(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The authentication code is invalid or expired.');

        $challenge = AuthLoginChallenge::query()->findOrFail($challengeId);
        $this->assertSame(1, $challenge->failed_attempts);
        $this->assertDatabaseHas('auth_audit_logs', ['event_type' => 'otp_failed']);
    }

    public function test_otp_challenge_locks_after_max_failed_attempts(): void
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
        $this->assertDatabaseHas('auth_audit_logs', ['event_type' => 'otp_challenge_locked']);

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])->assertStatus(422);
    }

    public function test_expired_otp_challenge_requires_full_login_again(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        Carbon::setTestNow('2026-07-01 10:10:00');

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

    public function test_soft_deleted_user_login_fails_with_safe_generic_error(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $user->delete();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertCookieMissing('refresh_token');
    }

    public function test_refresh_token_for_disabled_user_fails_and_clears_cookie(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $created = app(RefreshTokenService::class)->createForUser($user);
        $user->delete();

        $this->withUnencryptedCookie('refresh_token', $created['plain_token'])
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Refresh session is invalid or expired.')
            ->assertCookieExpired('refresh_token');

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => 'refresh_blocked_disabled_user',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_deleting_user_revokes_active_refresh_tokens(): void
    {
        $admin = $this->adminUser();
        $target = $this->enableTwoFactor($this->guardUser());
        $created = app(RefreshTokenService::class)->createForUser($target);

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/users/'.$target->getKey())
            ->assertOk();

        $token = RefreshToken::query()->findOrFail($created['model']->getKey());
        $this->assertNotNull($token->revoked_at);
        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => 'user_disabled_sessions_revoked',
            'user_id' => $target->getKey(),
        ]);
    }

    public function test_protected_api_rejects_disabled_user_after_disable(): void
    {
        $admin = $this->adminUser();
        $target = $this->enableTwoFactor($this->guardUser());
        $accessToken = $this->loginWithOtp($target)['verify']->json('data.access_token');

        $this->actingAs($admin, 'api')
            ->deleteJson('/api/users/'.$target->getKey())
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_otp_verification_fails_safely_when_user_disabled_before_session_issuance(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $challengeId = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.login_challenge_id');

        $user->delete();

        $this->postJson('/api/auth/otp/verify', [
            'login_challenge_id' => $challengeId,
            'otp' => $this->currentTotp(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The authentication code is invalid or expired.')
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    /**
     * @return array<string, string>
     */
    private function serverVariables(string $ip): array
    {
        return ['REMOTE_ADDR' => $ip];
    }

    private function lockLoginFor(string $email, string $ip): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ], $this->serverVariables($ip));
        }
    }
}

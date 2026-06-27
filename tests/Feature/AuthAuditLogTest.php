<?php

namespace Tests\Feature;

use App\Models\AuthAuditLog;
use App\Services\Auth\AuthAuditService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthAuditLogTest extends TestCase
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
        ]);
    }

    public function test_failed_password_login_writes_failure_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_LOGIN_PASSWORD_FAILURE,
            'status' => AuthAuditService::STATUS_FAILURE,
            'email' => strtolower($user->email),
        ]);
    }

    public function test_successful_password_credential_stage_writes_success_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_LOGIN_PASSWORD_SUCCESS,
            'status' => AuthAuditService::STATUS_SUCCESS,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_otp_failure_writes_failure_audit_row(): void
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

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_OTP_FAILURE,
            'status' => AuthAuditService::STATUS_FAILURE,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_otp_success_writes_success_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $this->loginWithOtp($user)['verify']->assertOk();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_OTP_SUCCESS,
            'status' => AuthAuditService::STATUS_SUCCESS,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_refresh_success_writes_success_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $cookie = $login->getCookie('refresh_token', false);

        $this->withUnencryptedCookie('refresh_token', $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertOk();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_REFRESH_SUCCESS,
            'status' => AuthAuditService::STATUS_SUCCESS,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_refresh_failure_writes_failure_audit_row(): void
    {
        $this->postJson('/api/auth/refresh')->assertUnauthorized();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_REFRESH_FAILURE,
            'status' => AuthAuditService::STATUS_FAILURE,
        ]);
    }

    public function test_logout_writes_success_audit_row(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $login = $this->loginWithOtp($user)['verify'];
        $token = $login->json('data.access_token');
        $cookie = $login->getCookie('refresh_token', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withUnencryptedCookie('refresh_token', $cookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event_type' => AuthAuditService::EVENT_LOGOUT_SUCCESS,
            'status' => AuthAuditService::STATUS_SUCCESS,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_audit_metadata_does_not_store_sensitive_values(): void
    {
        $auditService = app(AuthAuditService::class);
        $user = $this->guardUser();

        $log = $auditService->record(
            AuthAuditService::EVENT_LOGIN_PASSWORD_FAILURE,
            AuthAuditService::STATUS_FAILURE,
            user: $user,
            metadata: [
                'password' => 'SecretPassword1!',
                'otp' => '123456',
                'refresh_token' => bin2hex(random_bytes(32)),
                'token_hash' => hash('sha256', 'token'),
                'safe' => 'allowed',
            ],
        );

        $this->assertSame(['safe' => 'allowed'], $log->metadata);
        $this->assertStringNotContainsString('SecretPassword1!', json_encode($log->metadata));
        $this->assertStringNotContainsString('123456', json_encode($log->metadata));
    }

    public function test_admin_can_list_audit_logs(): void
    {
        $admin = $this->adminUser();
        $user = $this->enableTwoFactor($this->guardUser());

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/auth/audit-logs');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'action', 'status', 'email', 'occurred_at']]]);
    }

    public function test_guard_cannot_list_audit_logs(): void
    {
        $guard = $this->enableTwoFactor($this->guardUser());

        $this->actingAs($guard, 'api')
            ->getJson('/api/auth/audit-logs')
            ->assertForbidden();
    }

    public function test_audit_log_filters_work_by_action_status_email_and_date(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $admin = $this->adminUser();
        $user = $this->enableTwoFactor($this->guardUser());

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/auth/audit-logs?action='.AuthAuditService::EVENT_LOGIN_PASSWORD_FAILURE)
            ->assertOk()
            ->assertJsonPath('data.0.action', AuthAuditService::EVENT_LOGIN_PASSWORD_FAILURE);

        $this->actingAs($admin, 'api')
            ->getJson('/api/auth/audit-logs?status='.AuthAuditService::STATUS_FAILURE)
            ->assertOk()
            ->assertJsonPath('data.0.status', AuthAuditService::STATUS_FAILURE);

        $this->actingAs($admin, 'api')
            ->getJson('/api/auth/audit-logs?email='.urlencode($user->email))
            ->assertOk()
            ->assertJsonPath('data.0.email', strtolower($user->email));

        $this->actingAs($admin, 'api')
            ->getJson('/api/auth/audit-logs?date_from=2026-07-02&date_to=2026-07-02')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Carbon::setTestNow();
    }
}

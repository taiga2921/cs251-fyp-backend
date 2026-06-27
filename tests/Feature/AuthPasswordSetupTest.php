<?php

namespace Tests\Feature;

use App\Models\PasswordSetupToken;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthPasswordSetupTest extends TestCase
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
            'auth_security.password_setup_token_ttl_hours' => 24,
        ]);
    }

    public function test_existing_factory_users_default_to_setup_not_required(): void
    {
        $user = $this->guardUser();

        $this->assertFalse($user->setup_required);
    }

    public function test_admin_created_user_has_setup_required_and_one_time_setup_token(): void
    {
        $admin = $this->adminUser();
        $roleId = Role::query()->where('name', 'Guard')->value('id');

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/users', [
                'name' => 'New Guard',
                'email' => 'newguard@example.com',
                'password' => 'TempPassword1!',
                'role_id' => $roleId,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.setup_required', true)
            ->assertJsonStructure([
                'data' => ['id', 'email', 'setup_required'],
                'password_setup' => ['token', 'expires_at'],
            ]);

        $plainToken = $response->json('password_setup.token');
        $this->assertNotEmpty($plainToken);
        $this->assertDatabaseHas('password_setup_tokens', [
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $this->assertDatabaseMissing('password_setup_tokens', [
            'token_hash' => $plainToken,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/users/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonMissing(['password_setup']);
    }

    public function test_setup_required_login_returns_password_setup_step_without_tokens(): void
    {
        $user = User::factory()->setupRequired()->create([
            'role_id' => Role::query()->where('name', 'Guard')->value('id'),
            'password' => Hash::make('TempPassword1!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.next_step', 'password_setup_required')
            ->assertJsonStructure([
                'data' => [
                    'setup_token',
                    'expires_in',
                    'user' => ['email', 'setup_required'],
                ],
            ])
            ->assertJsonMissing(['data' => ['access_token' => true]]);

        $this->assertNull($response->json('data.access_token'));
        $response->assertCookieMissing('refresh_token');
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_setup_token_expires_and_cannot_complete_setup(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ]);

        $setupToken = $loginResponse->json('data.setup_token');

        Carbon::setTestNow(now()->addHours(25));

        $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Password setup token is invalid or expired.');
    }

    public function test_used_setup_token_cannot_be_reused(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ]);

        $setupToken = $loginResponse->json('data.setup_token');

        $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ])->assertOk();

        $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'AnotherStrongPassword1!',
            'password_confirmation' => 'AnotherStrongPassword1!',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Password setup token is invalid or expired.');
    }

    public function test_valid_setup_token_completes_password_setup(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
            'last_password_changed_at' => null,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ]);

        $setupToken = $loginResponse->json('data.setup_token');

        $completeResponse = $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ]);

        $completeResponse->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_required')
            ->assertJsonStructure(['data' => ['two_factor_setup_token', 'expires_in']])
            ->assertJsonPath('data.user.setup_required', false);

        $user->refresh();
        $this->assertFalse($user->setup_required);
        $this->assertNotNull($user->last_password_changed_at);
        $this->assertTrue(Hash::check('NewStrongPassword1!', $user->password));
        $this->assertFalse(Hash::check('TempPassword1!', $user->password));
    }

    public function test_new_password_allows_normal_login_after_setup(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);

        $setupToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ])->json('data.setup_token');

        $this->postJson('/api/auth/password-setup/complete', [
            'setup_token' => $setupToken,
            'password' => 'NewStrongPassword1!',
            'password_confirmation' => 'NewStrongPassword1!',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'TempPassword1!',
        ])->assertUnauthorized();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'NewStrongPassword1!',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_required')
            ->assertCookieMissing('refresh_token');
    }

    public function test_completed_setup_user_with_two_factor_can_login_after_otp(): void
    {
        $user = User::factory()->create([
            'setup_required' => false,
            'password' => Hash::make('ExistingPassword1!'),
        ]);
        $user = $this->enableTwoFactor($user);

        $verify = $this->loginWithOtp($user, 'ExistingPassword1!')['verify'];

        $verify->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertCookie('refresh_token');

        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_completed_setup_user_without_two_factor_routes_to_setup(): void
    {
        $user = User::factory()->create([
            'setup_required' => false,
            'two_factor_enabled' => false,
            'password' => Hash::make('ExistingPassword1!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ExistingPassword1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'two_factor_setup_required')
            ->assertCookieMissing('refresh_token');
    }

    public function test_admin_user_creation_rejects_password_shorter_than_configured_minimum(): void
    {
        $admin = $this->adminUser();
        $roleId = Role::query()->where('name', 'Guard')->value('id');

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Short Password User',
                'email' => 'shortpass@example.com',
                'password' => 'Short1!',
                'role_id' => $roleId,
            ])
            ->assertStatus(422);

        $errors = $response->json('data.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_admin_user_creation_accepts_password_meeting_configured_minimum(): void
    {
        $admin = $this->adminUser();
        $roleId = Role::query()->where('name', 'Guard')->value('id');

        $this->actingAs($admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Valid Password User',
                'email' => 'validpass@example.com',
                'password' => 'ValidPassword1!',
                'role_id' => $roleId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.setup_required', true);
    }

    public function test_admin_user_creation_rejects_two_factor_fields(): void
    {
        $admin = $this->adminUser();
        $roleId = Role::query()->where('name', 'Guard')->value('id');

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Two Factor User',
                'email' => 'twofactor@example.com',
                'password' => 'ValidPassword1!',
                'role_id' => $roleId,
                'two_factor_enabled' => true,
                'two_factor_secret' => 'secret-value',
            ])
            ->assertStatus(422);

        $errors = $response->json('data.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('two_factor_enabled', $errors);
        $this->assertArrayHasKey('two_factor_secret', $errors);
    }

    public function test_admin_user_update_rejects_two_factor_and_password_timestamp_fields(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create([
            'role_id' => Role::query()->where('name', 'Guard')->value('id'),
            'last_password_changed_at' => null,
        ]);

        $response = $this->actingAs($admin, 'api')
            ->patchJson('/api/users/'.$target->getKey(), [
                'two_factor_enabled' => true,
                'two_factor_secret' => 'secret-value',
                'last_password_changed_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422);

        $errors = $response->json('data.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('two_factor_enabled', $errors);
        $this->assertArrayHasKey('two_factor_secret', $errors);
        $this->assertArrayHasKey('last_password_changed_at', $errors);

        $target->refresh();
        $this->assertFalse($target->two_factor_enabled);
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->last_password_changed_at);
    }

    public function test_admin_user_password_update_sets_last_password_changed_at(): void
    {
        Carbon::setTestNow('2026-06-28 14:00:00');

        $admin = $this->adminUser();
        $target = User::factory()->create([
            'role_id' => Role::query()->where('name', 'Guard')->value('id'),
            'password' => Hash::make('ExistingPassword1!'),
            'last_password_changed_at' => null,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/users/'.$target->getKey(), [
                'password' => 'UpdatedPassword1!',
            ])
            ->assertOk();

        $target->refresh();
        $this->assertTrue(Hash::check('UpdatedPassword1!', $target->password));
        $this->assertNotNull($target->last_password_changed_at);
        $this->assertTrue($target->last_password_changed_at->equalTo(Carbon::parse('2026-06-28 14:00:00')));

        Carbon::setTestNow();
    }

    public function test_admin_user_non_password_update_does_not_change_last_password_changed_at(): void
    {
        $admin = $this->adminUser();
        $originalTimestamp = Carbon::parse('2026-01-15 10:30:00');
        $target = User::factory()->create([
            'role_id' => Role::query()->where('name', 'Guard')->value('id'),
            'last_password_changed_at' => $originalTimestamp,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/users/'.$target->getKey(), [
                'name' => 'Renamed Guard User',
                'phone' => '555-0100',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Guard User');

        $target->refresh();
        $this->assertTrue($target->last_password_changed_at->equalTo($originalTimestamp));
    }

    private function extractRefreshCookie($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('auth_security.refresh_cookie_name')) {
                return $cookie;
            }
        }

        return null;
    }
}

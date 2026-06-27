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
use Tests\TestCase;

class AuthPasswordSetupTest extends TestCase
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
                'password' => 'TempPass1!',
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
            ->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '')
            ->assertCookie('refresh_token');
    }

    public function test_completed_setup_user_login_still_issues_refresh_session(): void
    {
        $user = User::factory()->create([
            'setup_required' => false,
            'password' => Hash::make('ExistingPassword1!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ExistingPassword1!',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertCookie('refresh_token');

        $this->assertDatabaseCount('refresh_tokens', 1);
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

<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\Concerns\EnablesTwoFactorAuth;
use Tests\TestCase;

class AuthCorsTest extends TestCase
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
            'cors.allowed_origins' => ['http://localhost:5173', 'http://localhost:3000'],
            'cors.supports_credentials' => true,
        ]);
    }

    public function test_cors_preflight_from_allowed_origin_allows_credentials(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'content-type')
            ->options('/api/auth/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_login_from_allowed_origin_includes_credentialed_cors_headers(): void
    {
        $user = $this->enableTwoFactor($this->guardUser());
        $origin = 'http://localhost:5173';

        $loginResponse = $this->withHeader('Origin', $origin)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $loginResponse->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.next_step', 'otp_required');

        $response = $this->withHeader('Origin', $origin)
            ->postJson('/api/auth/otp/verify', [
                'login_challenge_id' => $loginResponse->json('data.login_challenge_id'),
                'otp' => $this->currentTotp(),
            ]);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
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
    }
}

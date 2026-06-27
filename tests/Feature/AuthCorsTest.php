<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class AuthCorsTest extends TestCase
{
    use CreatesPatrolUsers;
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

    public function test_login_from_allowed_origin_sets_refresh_cookie(): void
    {
        $user = $this->guardUser();

        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertCookie('refresh_token');
    }
}

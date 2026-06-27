<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\PatrolSession;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class PatrolTokenExpiryTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config([
            'jwt.secret' => 'test-jwt-secret-key-for-auth-tests-32chars',
            'jwt.ttl' => 1,
            'auth_security.access_token_ttl_minutes' => 1,
            'auth_security.refresh_token_ttl_hours' => 12,
            'auth_security.refresh_cookie_name' => 'refresh_token',
            'auth_security.refresh_cookie_path' => '/api/auth',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_guard_can_sync_pwa_location_after_access_token_expiry_using_valid_refresh_session(): void
    {
        $this->travelTo(Carbon::parse('2026-06-27 10:00:00'));

        $user = $this->guardUser();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $locationLogId = (string) Str::uuid();
        $payload = $this->validPayload($locationLogId, $user, $patrol);

        $this->postJson('/api/pwa/sync', $payload)
            ->assertUnauthorized();

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse->assertOk()
            ->assertCookie('refresh_token');

        $loginAccessToken = $loginResponse->json('data.access_token');
        $refreshCookie = $this->extractRefreshCookie($loginResponse);
        $this->assertArrayNotHasKey('refresh_token', $loginResponse->json('data'));

        $refreshResponse = $this->withUnencryptedCookie($refreshCookie->getName(), $refreshCookie->getValue())
            ->withCredentials()
            ->postJson('/api/auth/refresh');

        $refreshResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user', 'role']])
            ->assertCookie('refresh_token');

        $newAccessToken = $refreshResponse->json('data.access_token');
        $this->assertNotSame($loginAccessToken, $newAccessToken);
        $this->assertArrayNotHasKey('refresh_token', $refreshResponse->json('data'));

        $rotatedCookie = $this->extractRefreshCookie($refreshResponse);
        $this->assertNotSame($refreshCookie->getValue(), $rotatedCookie->getValue());

        $this->withHeader('Authorization', 'Bearer '.$newAccessToken)
            ->postJson('/api/pwa/sync', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.duplicate', false);

        $this->assertNotNull(LocationLog::query()->find($locationLogId));
    }

    public function test_expired_refresh_session_cannot_restore_pwa_sync_after_access_token_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-06-27 10:00:00'));

        $user = $this->guardUser();
        $patrol = PatrolSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $locationLogId = (string) Str::uuid();
        $payload = $this->validPayload($locationLogId, $user, $patrol);

        $refreshSession = app(RefreshTokenService::class)->createForUser($user, Request::create('/', 'GET'));
        $refreshCookieName = $this->refreshCookieName();

        auth('api')->forgetUser();
        $this->travel(1)->days();

        $this->postJson('/api/pwa/sync', $payload)
            ->assertUnauthorized();

        $this->withUnencryptedCookie($refreshCookieName, $refreshSession['plain_token'])
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Refresh session is invalid or expired.');

        $this->assertNull(LocationLog::query()->find($locationLogId));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(string $locationLogId, User $user, PatrolSession $patrol): array
    {
        return [
            'type' => 'location_log',
            'locationLogId' => $locationLogId,
            'patrolId' => $patrol->id,
            'userId' => $user->id,
            'timestamp' => now()->getTimestampMs(),
            'lat' => 3.139,
            'lng' => 101.6869,
            'accuracy' => 12.5,
            'source' => 'live',
            'trackingState' => 'active',
            'speed' => null,
            'heading' => null,
        ];
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

<?php

namespace Tests\Unit\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\InvalidRefreshTokenException;
use App\Services\Auth\RefreshTokenReuseException;
use App\Services\Auth\RefreshTokenService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class RefreshTokenServiceTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    private RefreshTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config([
            'jwt.secret' => 'test-jwt-secret-key-for-auth-tests-32chars',
            'auth_security.refresh_token_ttl_hours' => 12,
            'auth_security.refresh_cookie_name' => 'refresh_token',
        ]);

        $this->service = app(RefreshTokenService::class);
    }

    public function test_refresh_token_row_can_be_created_with_hash_only(): void
    {
        $user = $this->guardUser();
        $request = Request::create('/api/auth/login', 'POST');

        $result = $this->service->createForUser($user, $request);

        $this->assertInstanceOf(RefreshToken::class, $result['model']);
        $this->assertNotSame('', $result['plain_token']);
        $this->assertSame(
            hash('sha256', $result['plain_token']),
            $result['model']->token_hash
        );
        $this->assertDatabaseHas('refresh_tokens', [
            'id' => $result['model']->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => $result['plain_token'],
        ]);
    }

    public function test_active_revoked_expired_and_rotated_checks(): void
    {
        $active = RefreshToken::factory()->create();
        $expired = RefreshToken::factory()->expired()->create();
        $revoked = RefreshToken::factory()->revoked()->create();
        $rotated = RefreshToken::factory()->rotated()->create();

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isExpired());
        $this->assertFalse($active->isRevoked());
        $this->assertFalse($active->isRotated());

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isActive());

        $this->assertTrue($revoked->isRevoked());
        $this->assertFalse($revoked->isActive());

        $this->assertTrue($rotated->isRotated());
        $this->assertFalse($rotated->isActive());
    }

    public function test_validate_plain_token_rejects_expired_token(): void
    {
        RefreshToken::factory()->expired()->create([
            'token_hash' => hash('sha256', 'expired-token'),
        ]);

        $this->expectException(InvalidRefreshTokenException::class);
        $this->service->validatePlainToken('expired-token');
    }

    public function test_rotated_token_reuse_revokes_token_family(): void
    {
        $user = $this->guardUser();
        $created = $this->service->createForUser($user);
        $family = $created['model']->token_family;

        $this->service->rotate($created['model']);

        $this->expectException(RefreshTokenReuseException::class);
        try {
            $this->service->validatePlainToken($created['plain_token']);
        } finally {
            $this->assertSame(
                2,
                RefreshToken::query()->where('token_family', $family)->whereNotNull('revoked_at')->count()
            );
        }
    }

    public function test_rotate_issues_new_token_in_same_family_and_marks_old_token_rotated(): void
    {
        $created = $this->service->createForUser($this->guardUser());
        $family = $created['model']->token_family;

        $rotated = $this->service->rotate($created['model']);

        $created['model']->refresh();

        $this->assertNotNull($created['model']->rotated_at);
        $this->assertSame($family, $rotated['model']->token_family);
        $this->assertNotSame($created['model']->id, $rotated['model']->id);
        $this->assertTrue($rotated['model']->isActive());
    }

    public function test_revoke_marks_token_revoked(): void
    {
        $created = $this->service->createForUser($this->guardUser());

        $this->service->revoke($created['model']);

        $created['model']->refresh();
        $this->assertTrue($created['model']->isRevoked());
    }

    public function test_make_cookie_is_http_only_and_uses_configured_name(): void
    {
        $cookie = $this->service->makeCookie('plain-token-value');

        $this->assertSame('refresh_token', $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('plain-token-value', $cookie->getValue());
    }

    public function test_expired_token_validation_fails_after_time_passes(): void
    {
        Carbon::setTestNow('2026-06-27 10:00:00');

        $created = $this->service->createForUser($this->guardUser());

        Carbon::setTestNow('2026-06-28 10:00:00');

        $this->expectException(InvalidRefreshTokenException::class);
        $this->service->validatePlainToken($created['plain_token']);

        Carbon::setTestNow();
    }
}

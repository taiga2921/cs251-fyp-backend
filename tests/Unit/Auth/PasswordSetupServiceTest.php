<?php

namespace Tests\Unit\Auth;

use App\Models\PasswordSetupToken;
use App\Models\User;
use App\Services\Auth\InvalidPasswordSetupTokenException;
use App\Services\Auth\PasswordSetupService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesPatrolUsers;
use Tests\TestCase;

class PasswordSetupServiceTest extends TestCase
{
    use CreatesPatrolUsers;
    use RefreshDatabase;

    private PasswordSetupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'auth_security.password_setup_token_ttl_hours' => 24,
            'auth_security.password_min_length' => 12,
        ]);

        $this->service = app(PasswordSetupService::class);
    }

    public function test_create_for_user_stores_only_token_hash(): void
    {
        $user = User::factory()->setupRequired()->create();

        $created = $this->service->createForUser($user);

        $this->assertNotEmpty($created['plain_token']);
        $this->assertDatabaseHas('password_setup_tokens', [
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $created['plain_token']),
        ]);
        $this->assertDatabaseMissing('password_setup_tokens', [
            'token_hash' => $created['plain_token'],
        ]);
    }

    public function test_create_for_user_invalidates_previous_unused_tokens(): void
    {
        $user = User::factory()->setupRequired()->create();

        $first = $this->service->createForUser($user);
        $second = $this->service->createForUser($user);

        $this->assertDatabaseCount('password_setup_tokens', 1);
        $this->assertDatabaseHas('password_setup_tokens', [
            'token_hash' => hash('sha256', $second['plain_token']),
        ]);
        $this->assertNull($this->service->findByPlainToken($first['plain_token']));
    }

    public function test_only_latest_setup_token_can_complete_password_setup(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);

        $first = $this->service->createForUser($user);
        $second = $this->service->createForUser($user);

        try {
            $this->service->completeSetup($first['plain_token'], 'NewStrongPassword1!');
            $this->fail('Expected InvalidPasswordSetupTokenException for superseded setup token.');
        } catch (InvalidPasswordSetupTokenException) {
            // Expected: earlier plain token was invalidated when the latest token was created.
        }

        $updated = $this->service->completeSetup($second['plain_token'], 'NewStrongPassword1!');
        $this->assertFalse($updated->setup_required);
    }

    public function test_validate_plain_token_rejects_expired_token(): void
    {
        $user = User::factory()->setupRequired()->create();
        $created = $this->service->createForUser($user);

        Carbon::setTestNow(now()->addHours(25));

        $this->expectException(InvalidPasswordSetupTokenException::class);
        $this->service->validatePlainToken($created['plain_token']);
    }

    public function test_validate_plain_token_rejects_used_token(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
        ]);
        $created = $this->service->createForUser($user);

        $this->service->completeSetup($created['plain_token'], 'NewStrongPassword1!');

        $this->expectException(InvalidPasswordSetupTokenException::class);
        $this->service->validatePlainToken($created['plain_token']);
    }

    public function test_complete_setup_updates_user_and_marks_token_used(): void
    {
        $user = User::factory()->setupRequired()->create([
            'password' => Hash::make('TempPassword1!'),
            'last_password_changed_at' => null,
        ]);
        $created = $this->service->createForUser($user);

        $updated = $this->service->completeSetup($created['plain_token'], 'NewStrongPassword1!');

        $this->assertFalse($updated->setup_required);
        $this->assertNotNull($updated->last_password_changed_at);
        $this->assertTrue(Hash::check('NewStrongPassword1!', $updated->password));

        $token = PasswordSetupToken::query()->first();
        $this->assertNotNull($token?->used_at);
    }
}

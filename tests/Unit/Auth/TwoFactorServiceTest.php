<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['auth_security.totp_issuer' => 'IKH One', 'auth_security.totp_window' => 1]);

        $this->service = app(TwoFactorService::class);
    }

    public function test_generate_secret_returns_base32_string(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_build_otpauth_uri_contains_email_and_secret(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $secret = 'JBSWY3DPEHPK3PXP';

        $uri = $this->service->buildOtpauthUri($user, $secret);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('user%40example.com', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
    }

    public function test_generate_totp_is_consistent_with_verify_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $otp = $this->service->generateTotp($secret);

        $this->assertTrue($this->service->verifyCode($secret, $otp));
    }

    public function test_verify_code_accepts_valid_otp(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $otp = $this->service->generateTotp($secret, 59);

        $this->assertTrue($this->service->verifyCode($secret, $otp, 59));
    }

    public function test_verify_code_rejects_invalid_otp(): void
    {
        $this->assertFalse($this->service->verifyCode('JBSWY3DPEHPK3PXP', '000000', 59));
    }

    public function test_encrypt_secret_does_not_store_plaintext(): void
    {
        $plain = 'JBSWY3DPEHPK3PXP';
        $encrypted = $this->service->encryptSecret($plain);

        $this->assertNotSame($plain, $encrypted);
        $this->assertSame($plain, $this->service->decryptSecret($encrypted));
    }
}

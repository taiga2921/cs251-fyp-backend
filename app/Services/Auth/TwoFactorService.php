<?php

namespace App\Services\Auth;

use App\Models\User;
use RuntimeException;

class TwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function buildOtpauthUri(User $user, string $plainSecret): string
    {
        $issuer = (string) config('auth_security.totp_issuer', 'IKH One');
        $label = rawurlencode($issuer.':'.$user->email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $plainSecret,
            rawurlencode($issuer)
        );
    }

    public function verifyCode(string $plainSecret, string $code, ?int $timestamp = null): bool
    {
        $normalizedCode = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($normalizedCode) !== 6) {
            return false;
        }

        $window = (int) config('auth_security.totp_window', 1);
        $timestamp = $timestamp ?? time();
        $period = 30;

        for ($offset = -$window; $offset <= $window; $offset++) {
            if ($this->generateTotp($plainSecret, $timestamp + ($offset * $period)) === $normalizedCode) {
                return true;
            }
        }

        return false;
    }

    public function generateTotp(string $plainSecret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = pack('N*', 0, intdiv($timestamp, 30));
        $key = $this->base32Decode($plainSecret);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function encryptSecret(string $plainSecret): string
    {
        return encrypt($plainSecret);
    }

    public function decryptSecret(string $encryptedSecret): string
    {
        return decrypt($encryptedSecret);
    }

    public function enableTwoFactorForUser(User $user, string $plainSecret): void
    {
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $this->encryptSecret($plainSecret),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function verifyForUser(User $user, string $code): bool
    {
        if (! $user->two_factor_enabled || $user->two_factor_secret === null) {
            return false;
        }

        $plainSecret = $this->decryptSecret($user->two_factor_secret);

        return $this->verifyCode($plainSecret, $code);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');

        if ($secret === '') {
            throw new RuntimeException('Invalid Base32 secret.');
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
            $value = strpos(self::BASE32_ALPHABET, $secret[$i]);

            if ($value === false) {
                throw new RuntimeException('Invalid Base32 character.');
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TwoFactorAuthentication
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn (): string => implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4)))
            ->all();
    }

    /** @param list<string> $codes */
    public function recoveryCodeHashes(array $codes): array
    {
        return array_map(fn (string $code): string => $this->recoveryHash($code), $codes);
    }

    public function provisioningUri(User $user): ?string
    {
        if (blank($user->two_factor_secret)) {
            return null;
        }

        $issuer = rawurlencode((string) config('app.name', 'BuildPusher'));
        $label = rawurlencode(config('app.name', 'BuildPusher').':'.$user->email);

        return "otpauth://totp/{$label}?secret={$user->two_factor_secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function verifyCode(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        foreach (range(-1, 1) as $offset) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function verifyUser(User $user, string $code, bool $consumeRecoveryCode = true): bool
    {
        if (filled($user->two_factor_secret) && $this->verifyCode($user->two_factor_secret, $code)) {
            return true;
        }

        $hash = $this->recoveryHash($code);
        if (! in_array($hash, $user->two_factor_recovery_codes ?? [], true)) {
            return false;
        }

        if ($consumeRecoveryCode) {
            DB::transaction(function () use ($user, $hash): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $codes = $locked->two_factor_recovery_codes ?? [];
                if (in_array($hash, $codes, true)) {
                    $locked->forceFill([
                        'two_factor_recovery_codes' => array_values(array_diff($codes, [$hash])),
                    ])->save();
                }
            });
        }

        return true;
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function recoveryHash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '')) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}

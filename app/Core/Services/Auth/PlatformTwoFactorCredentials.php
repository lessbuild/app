<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/** Shares the stored TOTP and recovery-code format used by Core authentication and account security. */
final class PlatformTwoFactorCredentials
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function encryptSecret(string $secret): string
    {
        return Crypt::encrypt($secret);
    }

    public function decryptSecret(mixed $encrypted): ?string
    {
        return $this->decryptString($encrypted);
    }

    public function provisioningUri(PlatformUser $user, string $secret): string
    {
        $issuer = rawurlencode((string) config('app.name', 'Buildpusher'));
        $label = rawurlencode((string) config('app.name', 'Buildpusher').':'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function validTotp(string $secret, string $submittedCode, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $submittedCode) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $key = $this->base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        foreach (range(-1, 1) as $offset) {
            $hash = hash_hmac('sha1', pack('N2', 0, $counter + $offset), $key, true);
            $position = ord($hash[19]) & 0x0F;
            $binary = ((ord($hash[$position]) & 0x7F) << 24)
                | ((ord($hash[$position + 1]) & 0xFF) << 16)
                | ((ord($hash[$position + 2]) & 0xFF) << 8)
                | (ord($hash[$position + 3]) & 0xFF);
            $expected = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn (): string => implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4)))
            ->all();
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code): string => $this->recoveryCodeHash($code), $codes);
    }

    public function encryptRecoveryHashes(array $hashes): string
    {
        return Crypt::encrypt(json_encode(array_values($hashes), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function decryptRecoveryHashes(mixed $encrypted): array
    {
        $json = $this->decryptString($encrypted);
        if ($json === null) {
            return [];
        }

        try {
            $hashes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($hashes)
            ? array_values(array_filter($hashes, 'is_string'))
            : [];
    }

    public function recoveryCodeHash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }

    /** @param list<string> $recoveryHashes */
    public function matches(string $secret, array $recoveryHashes, string $submittedCode): bool
    {
        $plainSecret = $this->decryptSecret($secret);
        if ($plainSecret !== null && $this->validTotp($plainSecret, $submittedCode)) {
            return true;
        }

        $submittedHash = $this->recoveryCodeHash($submittedCode);

        foreach ($recoveryHashes as $recoveryHash) {
            if (hash_equals($recoveryHash, $submittedHash)) {
                return true;
            }
        }

        return false;
    }

    private function decryptString(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decrypt($encrypted);
        } catch (DecryptException) {
            return null;
        }

        return is_string($decrypted) ? $decrypted : null;
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
        $characters = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
        foreach (str_split($characters) as $character) {
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

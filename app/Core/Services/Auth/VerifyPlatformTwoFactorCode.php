<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/** Verifies imported TOTP secrets and consumes the hashed recovery codes used by the source apps. */
final class VerifyPlatformTwoFactorCode
{
    public function handle(PlatformUser $user, string $submittedCode): bool
    {
        if (! $user->twoFactorEnabled()) {
            return false;
        }

        $code = preg_replace('/\D/', '', $submittedCode) ?? '';
        $secret = $this->decrypt($user->two_factor_secret);

        if (strlen($code) === 6 && $secret !== null && $this->validTotp($secret, $code)) {
            return true;
        }

        $normalizedCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $submittedCode) ?? '');
        if ($normalizedCode === '') {
            return false;
        }

        $recoveryHash = hash('sha256', $normalizedCode);

        return DB::connection('core')->transaction(function () use ($user, $recoveryHash): bool {
            $lockedUser = PlatformUser::query()->lockForUpdate()->find($user->getKey());
            if (! $lockedUser || ! $lockedUser->twoFactorEnabled()) {
                return false;
            }

            $recoveryCodes = $this->recoveryCodes($lockedUser->two_factor_recovery_codes);
            if (! in_array($recoveryHash, $recoveryCodes, true)) {
                return false;
            }

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => Crypt::encrypt(json_encode(
                    array_values(array_diff($recoveryCodes, [$recoveryHash])),
                    JSON_THROW_ON_ERROR,
                )),
            ])->save();

            return true;
        });
    }

    private function validTotp(string $secret, string $code): bool
    {
        $key = $this->decodeBase32($secret);
        if ($key === '') {
            return false;
        }

        $counter = intdiv(time(), 30);
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

    private function decodeBase32(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '')) as $character) {
            $position = strpos($alphabet, $character);
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

    /** @return list<string> */
    private function recoveryCodes(mixed $encryptedCodes): array
    {
        $decoded = $this->decrypt($encryptedCodes);
        if ($decoded === null) {
            return [];
        }

        try {
            $codes = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter($codes, 'is_string'));
    }

    private function decrypt(mixed $encrypted): ?string
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
}

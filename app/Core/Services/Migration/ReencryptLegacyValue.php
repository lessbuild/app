<?php

namespace App\Core\Services\Migration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Re-encrypt source application secrets with the Core application's key. */
final class ReencryptLegacyValue
{
    public function forCore(string $sourceProduct, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $plaintext = $value;

        if (Encrypter::appearsEncrypted($value)) {
            $configuredKey = config('migration.source_app_keys.'.$sourceProduct);
            $key = $this->decodeKey(is_string($configuredKey) && $configuredKey !== ''
                ? $configuredKey
                : (string) config('app.key'));

            if ($key === null) {
                return null;
            }

            try {
                $cipher = (string) config('migration.source_ciphers.'.$sourceProduct, config('app.cipher'));
                $sourceEncrypter = new Encrypter($key, $cipher);
                $decrypted = $sourceEncrypter->decryptString($value);
                $unserialized = @unserialize($decrypted, ['allowed_classes' => false]);
                $plaintext = is_string($unserialized) ? $unserialized : $decrypted;
            } catch (DecryptException|RuntimeException) {
                return null;
            }
        }

        return Crypt::encrypt($plaintext);
    }

    private function decodeKey(string $key): ?string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key === '' ? null : $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}

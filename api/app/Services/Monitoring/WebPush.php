<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\PlatformSetting;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

/**
 * Sends Web Push notifications (RFC 8030) with an encrypted payload (RFC 8291, aes128gcm) and VAPID (RFC 8292), using
 * only PHP's OpenSSL. The VAPID key pair is made once and kept, encrypted, in the platform settings. Only the browser
 * vendors' push services are contacted.
 */
final class WebPush
{
    /**
     * The push services browsers use, by host (a leading dot matches subdomains).
     *
     * @var list<string>
     */
    public const HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', '.push.apple.com', '.notify.windows.com'];

    /**
     * The DER prefix that turns an uncompressed P-256 point into a SubjectPublicKeyInfo.
     *
     * @var string
     */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Determine whether an address belongs to a browser vendor's push service over HTTPS.
     *
     * @param  string  $endpoint
     * @return bool
     */
    public static function allowed(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! is_string($host)) {
            return false;
        }
        foreach (self::HOSTS as $allowed) {
            if ($host === ltrim($allowed, '.') || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the public VAPID key browsers subscribe with (base64url of the uncompressed point), making the pair first.
     *
     * @return string
     */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /**
     * Send a notification to one device. Returns sent, gone (the device unsubscribed; forget it) or failed.
     *
     * @param  PushSubscription  $subscription
     * @param  array<string, mixed>  $message  title, body, url and tag, read by the service worker
     * @return string
     */
    public function send(PushSubscription $subscription, array $message): string
    {
        if (! self::allowed($subscription->endpoint)) {
            return 'gone';
        }
        try {
            $body = $this->encrypt((string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $subscription->public_key, $subscription->auth_secret);
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders(['TTL' => '86400', 'Urgency' => 'high', 'Content-Encoding' => 'aes128gcm', 'Authorization' => $this->vapid($subscription->endpoint)])
                ->withBody($body, 'application/octet-stream')->post($subscription->endpoint);
        } catch (Throwable) {
            return 'failed';
        }
        if (in_array($response->status(), [404, 410], true)) {
            return 'gone';
        }

        return $response->successful() ? 'sent' : 'failed';
    }

    /**
     * Encrypt a payload for a device (RFC 8291): a one-off key pair, ECDH with the device's key, HKDF with its auth
     * secret, and AES-128-GCM in one record.
     *
     * @param  string  $plaintext
     * @param  string  $devicePublicKey  base64url
     * @param  string  $authSecret  base64url
     * @return string
     */
    public function encrypt(string $plaintext, string $devicePublicKey, string $authSecret): string
    {
        $device = self::decode($devicePublicKey);
        $auth = self::decode($authSecret);
        if (strlen($device) !== 65 || strlen($auth) !== 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }
        $ephemeral = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (! $ephemeral instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not make a key.');
        }
        $local = $this->point($ephemeral);
        $shared = openssl_pkey_derive($this->publicKeyFromPoint($device), $ephemeral, 32);
        if ($shared === false) {
            throw new RuntimeException('Could not agree a key.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$device.$local, $auth);
        $salt = random_bytes(16);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt.');
        }

        return $salt.pack('N', 4096).chr(65).$local.$cipher.$tag;
    }

    /**
     * Encode bytes as base64url without padding.
     *
     * @param  string  $bytes
     * @return string
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode base64url, with or without padding.
     *
     * @param  string  $text
     * @return string
     */
    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    /**
     * Build the VAPID Authorization header for a push service: a JWT for its origin, valid for twelve hours, signed
     * with ES256.
     *
     * @param  string  $endpoint
     * @return string
     */
    private function vapid(string $endpoint): string
    {
        $keys = $this->keys();
        $origin = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);
        $input = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::encode((string) json_encode(['aud' => $origin, 'exp' => time() + 43200, 'sub' => 'mailto:'.config('mail.from.address', 'alerts@buildpusher.com')], JSON_UNESCAPED_SLASHES));
        $der = '';
        if (! openssl_sign($input, $der, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign.');
        }

        return 'vapid t='.$input.'.'.self::encode(self::rawSignature($der)).', k='.$keys['public'];
    }

    /**
     * Get the VAPID key pair, making and storing it the first time.
     *
     * @return array{public: string, private: string}
     */
    private function keys(): array
    {
        $stored = PlatformSetting::read('web_push.vapid');
        if (is_string($stored['public'] ?? null) && is_string($stored['private'] ?? null)) {
            return ['public' => $stored['public'], 'private' => $stored['private']];
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $private = '';
        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not make the VAPID keys.');
        }
        $keys = ['public' => self::encode($this->point($key)), 'private' => (string) $private];
        PlatformSetting::write('web_push.vapid', $keys);

        return $keys;
    }

    /**
     * Get a P-256 key's public point, uncompressed (0x04, X, Y).
     *
     * @param  OpenSSLAsymmetricKey  $key
     * @return string
     */
    private function point(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Could not read the key.');
        }

        return "\x04".str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Turn an uncompressed P-256 point into an OpenSSL public key.
     *
     * @param  string  $point
     * @return OpenSSLAsymmetricKey
     */
    private function publicKeyFromPoint(string $point): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode((string) hex2bin(self::P256_SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Invalid public key.');
        }

        return $key;
    }

    /**
     * Turn a DER ECDSA signature into the 64-byte R||S form JWTs use.
     *
     * @param  string  $der
     * @return string
     */
    private static function rawSignature(string $der): string
    {
        $offset = 2 + ((ord($der[1]) & 0x80) !== 0 ? ord($der[1]) & 0x7F : 0);
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\DomainProbe;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The domain check's look-ups over the network: TLS handshakes, HTTP requests and DNS queries, each with short timeouts. */
final class NetworkDomainProbe implements DomainProbe
{
    /**
     * Connect over TLS with verification and read the certificate; on failure, retry without verification to still
     * report the expiry, and say why it isn't trusted.
     *
     * @param  string  $host
     * @return array{valid: bool, expires_at: int|null, issuer: string|null, error: string|null}
     */
    public function certificate(string $host): array
    {
        $read = function (bool $verify) use ($host): array {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify, 'SNI_enabled' => true, 'peer_name' => $host,
            ]]);
            $errorNumber = 0;
            $errorText = '';
            $socket = @stream_socket_client("ssl://{$host}:443", $errorNumber, $errorText, 8, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                return ['cert' => null, 'error' => $errorText !== '' ? $errorText : 'connection failed'];
            }
            $params = stream_context_get_params($socket);
            fclose($socket);

            return ['cert' => $params['options']['ssl']['peer_certificate'] ?? null, 'error' => null];
        };
        $trusted = $read(true);
        $result = $trusted['cert'] !== null ? $trusted : $read(false);
        $parsed = $result['cert'] !== null ? openssl_x509_parse($result['cert']) : false;
        if ($parsed === false) {
            return ['valid' => false, 'expires_at' => null, 'issuer' => null, 'error' => $trusted['error'] ?? $result['error']];
        }

        return [
            'valid' => $trusted['cert'] !== null,
            'expires_at' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
            'issuer' => is_array($parsed['issuer'] ?? null) ? (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '') : null,
            'error' => $trusted['cert'] !== null ? null : ($trusted['error'] ?? 'not trusted'),
        ];
    }

    /**
     * Try a handshake limited to TLS 1.0 and 1.1.
     *
     * @param  string  $host
     * @return bool
     */
    public function acceptsOldTls(string $host): bool
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
            'security_level' => 0,
        ]]);
        $errorNumber = 0;
        $errorText = '';
        $socket = @stream_socket_client("ssl://{$host}:443", $errorNumber, $errorText, 6, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * Request a URL without following redirects.
     *
     * @param  string  $url
     * @return array{status: int, headers: array<string, string>}|null
     */
    public function fetch(string $url): ?array
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->connectTimeout(5)->withUserAgent('BuildPusher-Security/1.0')->get($url);
        } catch (Throwable) {
            return null;
        }
        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return ['status' => $response->status(), 'headers' => $headers];
    }

    /**
     * Get a name's TXT records.
     *
     * @param  string  $name
     * @return list<string>
     */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        return array_map(fn (array $record): string => (string) ($record['txt'] ?? ''), is_array($records) ? $records : []);
    }

    /**
     * Get the target of a name's CNAME record.
     *
     * @param  string  $name
     * @return string|null
     */
    public function cname(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_CNAME);
        $target = is_array($records) ? ($records[0]['target'] ?? null) : null;

        return is_string($target) && $target !== '' ? strtolower(rtrim($target, '.')) : null;
    }

    /**
     * Determine whether a name has an A or AAAA record.
     *
     * @param  string  $name
     * @return bool
     */
    public function resolves(string $name): bool
    {
        $records = @dns_get_record($name, DNS_A | DNS_AAAA);

        return is_array($records) && $records !== [];
    }
}

<?php

namespace App\Services;

use App\Models\BackupDestination;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class S3CompatibleStorageProbe
{
    private const SERVICE = 's3';

    private const TEST_CONTENT_TYPE = 'application/octet-stream';

    public function __construct(private readonly Factory $http) {}

    /**
     * Verify S3-compatible write, read, and delete access with one temporary object.
     *
     * @param  BackupDestination  $destination  Encrypted destination credentials and endpoint to verify.
     * @return void No remote object remains after a successful or failed probe when cleanup is possible.
     *
     * @throws RuntimeException If the destination is invalid, the temporary file cannot be used, or a storage operation fails.
     */
    public function handle(BackupDestination $destination): void
    {
        $objectKey = $this->objectKey($destination);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'buildpusher-backup-test-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary backup verification file.');
        }

        $remoteObjectCreated = false;

        try {
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to secure the temporary backup verification file.');
            }

            $contents = sprintf("BuildPusher backup destination verification %s\n", Str::uuid());
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to write the temporary backup verification file.');
            }

            $payload = file_get_contents($temporaryPath);
            if ($payload === false) {
                throw new RuntimeException('Unable to read the temporary backup verification file.');
            }

            $this->assertSuccessful('write', $this->request($destination, 'PUT', $objectKey, $payload));
            $remoteObjectCreated = true;

            $read = $this->request($destination, 'GET', $objectKey);
            $this->assertSuccessful('read', $read);
            if ($read->body() !== $payload) {
                throw new RuntimeException('Backup destination read verification returned unexpected content.');
            }

            $this->assertSuccessful('delete', $this->request($destination, 'DELETE', $objectKey));
            $remoteObjectCreated = false;
        } finally {
            if ($remoteObjectCreated) {
                try {
                    $this->assertSuccessful('cleanup', $this->request($destination, 'DELETE', $objectKey));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            @unlink($temporaryPath);
        }
    }

    /**
     * Send one signed path-style S3 request.
     *
     * @param  BackupDestination  $destination  Destination credentials and endpoint.
     * @param  string  $method  HTTP method supported by the probe.
     * @param  string  $objectKey  Temporary object key relative to the destination bucket.
     * @param  string  $body  Request payload for PUT, empty for GET and DELETE.
     * @return Response The raw HTTP response for status and body validation.
     */
    private function request(BackupDestination $destination, string $method, string $objectKey, string $body = ''): Response
    {
        $endpoint = $this->endpoint($destination);
        if (! preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/iD', (string) $destination->bucket)) {
            throw new RuntimeException('Backup bucket name is invalid.');
        }

        $canonicalUri = $this->path($endpoint['base_path'], $destination->bucket, $objectKey);
        $headers = $this->authorizationHeaders(
            $method,
            $canonicalUri,
            $endpoint['host'],
            trim((string) $destination->region),
            (string) $destination->access_key,
            (string) $destination->secret_key,
            $body,
        );
        $request = $this->http->createPendingRequest()
            ->connectTimeout(5)
            ->timeout(15)
            ->withHeaders($headers);

        if ($method === 'PUT') {
            $request->withBody($body, self::TEST_CONTENT_TYPE);
        }

        return $request->send($method, $endpoint['base_url'].$canonicalUri);
    }

    /**
     * Create a temporary key under the configured prefix without touching a website-specific Restic path.
     */
    private function objectKey(BackupDestination $destination): string
    {
        $prefix = trim((string) $destination->path_prefix, '/');
        if (preg_match('/\A[a-zA-Z0-9._\/-]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('Backup path prefix is invalid.');
        }

        return "{$prefix}/connection-tests/".Str::uuid().'.bin';
    }

    /**
     * Normalize and validate the HTTPS endpoint used by the signed request.
     *
     * @return array{base_url: string, host: string, base_path: string}
     */
    private function endpoint(BackupDestination $destination): array
    {
        $value = rtrim(trim((string) $destination->endpoint), '/');
        $parts = parse_url($value);
        if (! is_array($parts)) {
            throw new RuntimeException('Backup destination must use a valid HTTPS endpoint.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme !== 'https'
            || $host === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($port !== null && ($port < 1 || $port > 65535))) {
            throw new RuntimeException('Backup destination must use a valid HTTPS endpoint.');
        }

        $authority = $host;
        if ($port !== null && $port !== 443) {
            $authority .= ":{$port}";
        }

        return [
            'base_url' => "https://{$authority}",
            'host' => $authority,
            'base_path' => trim((string) ($parts['path'] ?? ''), '/'),
        ];
    }

    /**
     * Encode endpoint, bucket, and object segments consistently for both the URL and SigV4 canonical URI.
     */
    private function path(string ...$parts): string
    {
        $segments = [];
        foreach ($parts as $part) {
            foreach (explode('/', trim($part, '/')) as $segment) {
                if ($segment !== '') {
                    $segments[] = rawurlencode(rawurldecode($segment));
                }
            }
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Build AWS Signature Version 4 headers without logging credential material.
     *
     * @return array<string, string>
     */
    private function authorizationHeaders(
        string $method,
        string $canonicalUri,
        string $host,
        string $region,
        string $accessKey,
        string $secretKey,
        string $body,
    ): array {
        if ($accessKey === '' || $secretKey === '' || $region === '') {
            throw new RuntimeException('Backup destination credentials are incomplete.');
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $normalized = preg_replace('/\s+/', ' ', trim($value)) ?? '';
            $canonicalHeaders .= "{$name}:{$normalized}\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = $method."\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$date}/{$region}/".self::SERVICE.'/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n".hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            ...$headers,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    private function assertSuccessful(string $operation, Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $providerCode = $this->providerErrorCode($response);
        $detail = $providerCode === null ? '' : ", {$providerCode}";

        throw new RuntimeException("Backup destination {$operation} request failed (HTTP {$response->status()}{$detail}).");
    }

    /**
     * Extract only the provider's bounded machine-readable error code; never persist its response body.
     */
    private function providerErrorCode(Response $response): ?string
    {
        if (preg_match('/<Code>\s*([A-Za-z][A-Za-z0-9_-]{0,99})\s*<\/Code>/i', $response->body(), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}

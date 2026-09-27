<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\BackupDestination;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Proves a backup destination works: writes, reads back and deletes a small object with AWS Signature V4 requests. */
class S3StorageProbe
{
    public function __construct(private readonly Factory $http) {}

    /** @throws RuntimeException with a message safe to show (no response bodies) */
    public function check(BackupDestination $destination): void
    {
        $prefix = trim($destination->path_prefix, '/');
        if (preg_match('/\A[a-zA-Z0-9._\/-]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The backup path prefix is invalid.');
        }
        $key = "{$prefix}/connection-tests/".Str::uuid().'.bin';
        $payload = config('app.name').' backup destination check '.Str::uuid()."\n";
        $this->assertSuccessful('write', $this->request($destination, 'PUT', $key, $payload));
        try {
            $read = $this->request($destination, 'GET', $key);
            $this->assertSuccessful('read', $read);
            if ($read->body() !== $payload) {
                throw new RuntimeException('Reading the test object back returned different content.');
            }
        } finally {
            try {
                $this->assertSuccessful('delete', $this->request($destination, 'DELETE', $key));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function request(BackupDestination $destination, string $method, string $key, string $body = ''): Response
    {
        [$base, $host, $basePath] = $this->endpoint($destination->endpoint);
        if (preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/iD', $destination->bucket) !== 1) {
            throw new RuntimeException('The backup bucket name is invalid.');
        }
        $uri = $this->path($basePath, $destination->bucket, $key);
        $request = $this->http->connectTimeout(5)->timeout(15)
            ->withHeaders($this->signedHeaders($method, $uri, $host, trim($destination->region), $destination->access_key, $destination->secret_key, $body));
        if ($method === 'PUT') {
            $request->withBody($body, 'application/octet-stream');
        }

        return $request->send($method, $base.$uri);
    }

    /** @return array{string, string, string} base URL, host header and base path */
    private function endpoint(string $endpoint): array
    {
        $parts = parse_url(rtrim(trim($endpoint), '/'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Backup destinations must use a valid HTTPS endpoint.');
        }
        $authority = isset($parts['port']) && $parts['port'] !== 443 ? "{$host}:{$parts['port']}" : $host;

        return ["https://{$authority}", $authority, trim((string) ($parts['path'] ?? ''), '/')];
    }

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

    /** @return array<string, string> */
    private function signedHeaders(string $method, string $uri, string $host, string $region, string $accessKey, string $secretKey, string $body): array
    {
        if ($accessKey === '' || $secretKey === '' || $region === '') {
            throw new RuntimeException('The backup destination’s credentials are incomplete.');
        }
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }
        $signed = implode(';', array_keys($headers));
        $scope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', "{$method}\n{$uri}\n\n{$canonicalHeaders}\n{$signed}\n{$payloadHash}");
        $key = 'AWS4'.$secretKey;
        foreach ([$date, $region, 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return [...$headers, 'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signed}, Signature=".hash_hmac('sha256', $stringToSign, $key)];
    }

    private function assertSuccessful(string $operation, Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $code = preg_match('/<Code>\s*([A-Za-z][A-Za-z0-9_-]{0,99})\s*<\/Code>/i', $response->body(), $match) === 1 ? ", {$match[1]}" : '';

        throw new RuntimeException("The {$operation} request failed (HTTP {$response->status()}{$code}).");
    }
}

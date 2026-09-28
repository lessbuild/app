<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A small S3-compatible client (AWS, Backblaze B2, Cloudflare R2, DigitalOcean Spaces, MinIO…) that signs requests with
 * AWS Signature Version 4 and uses path-style addresses. Only HTTPS endpoints without credentials are accepted, and
 * errors never include response bodies.
 */
final class S3Client
{
    /**
     * Create a new S3Client instance.
     *
     * @param  Factory  $http  Makes the signed requests.
     */
    public function __construct(private readonly Factory $http) {}

    /**
     * Send one signed request for an object in a bucket.
     *
     * @param  S3Location  $location  Where the bucket is and how to sign in to it.
     * @param  string  $method  GET, PUT, HEAD or DELETE
     * @param  string  $key  The object's key inside the bucket.
     * @param  string  $body  The object's content, for PUT.
     * @param  int  $timeout  Seconds to wait for the whole request.
     * @return Response
     */
    public function request(S3Location $location, string $method, string $key, string $body = '', int $timeout = 15): Response
    {
        [$base, $host, $basePath] = $this->endpoint($location->endpoint);
        if (preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/iD', $location->bucket) !== 1) {
            throw new RuntimeException('The bucket name is invalid.');
        }
        $uri = $this->path($basePath, $location->bucket, $key);
        $request = $this->http->connectTimeout(5)->timeout($timeout)
            ->withHeaders($this->signedHeaders($method, $uri, $host, trim($location->region), $location->accessKey, $location->secretKey, $body));
        if ($method === 'PUT') {
            $request->withBody($body, 'application/octet-stream');
        }

        return $request->send($method, $base.$uri);
    }

    /**
     * Throw when a request failed, with the status and S3's error code but never the response body.
     *
     * @param  string  $operation  What was attempted, e.g. "upload".
     * @param  Response  $response
     * @return void
     *
     * @throws RuntimeException
     */
    public function assertSuccessful(string $operation, Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $code = preg_match('/<Code>\s*([A-Za-z][A-Za-z0-9_-]{0,99})\s*<\/Code>/i', $response->body(), $match) === 1 ? ", {$match[1]}" : '';

        throw new RuntimeException("The {$operation} request failed (HTTP {$response->status()}{$code}).");
    }

    /**
     * Split an HTTPS endpoint without credentials, query or fragment into its base URL, host and path.
     *
     * @param  string  $endpoint
     * @return array{string, string, string} base URL, host header and base path
     */
    private function endpoint(string $endpoint): array
    {
        $parts = parse_url(rtrim(trim($endpoint), '/'));
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Storage must use a valid HTTPS endpoint.');
        }
        $authority = isset($parts['port']) && $parts['port'] !== 443 ? "{$host}:{$parts['port']}" : $host;

        return ["https://{$authority}", $authority, trim((string) ($parts['path'] ?? ''), '/')];
    }

    /**
     * Build a request path from its parts, with each segment URL-encoded exactly once.
     *
     * @param  string  ...$parts
     * @return string
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
     * Build the headers for an AWS Signature Version 4 request, including the signed Authorization header.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  string  $host
     * @param  string  $region
     * @param  string  $accessKey
     * @param  string  $secretKey
     * @param  string  $body
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $uri, string $host, string $region, string $accessKey, string $secretKey, string $body): array
    {
        if ($accessKey === '' || $secretKey === '' || $region === '') {
            throw new RuntimeException('The storage credentials are incomplete.');
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
}

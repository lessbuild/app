<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/** Signs AWS API requests with Signature Version 4. */
final class AwsSignature
{
    /**
     * Sign a request and return the headers to send: the given ones plus X-Amz-Date and Authorization.
     *
     * @param  string  $accessKey
     * @param  string  $secret
     * @param  string  $region  e.g. eu-west-2
     * @param  string  $service  e.g. lightsail
     * @param  string  $method  GET or POST
     * @param  string  $url  the full URL, with any query string
     * @param  array<string, string>  $headers  headers to sign (Host is added from the URL)
     * @param  string  $body
     * @param  CarbonImmutable|null  $now
     * @return array<string, string>
     */
    public static function headers(string $accessKey, string $secret, string $region, string $service, string $method, string $url, array $headers, string $body, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $parts = parse_url($url);
        $headers = ['Host' => (string) ($parts['host'] ?? ''), ...$headers, 'X-Amz-Date' => $amzDate];

        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($canonical);
        $signedHeaders = implode(';', array_keys($canonical));
        $headerBlock = '';
        foreach ($canonical as $name => $value) {
            $headerBlock .= "{$name}:{$value}\n";
        }
        parse_str($parts['query'] ?? '', $query);
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $path = $parts['path'] ?? '/';
        $canonicalRequest = implode("\n", [strtoupper($method), $path === '' ? '/' : $path, $canonicalQuery, $headerBlock, $signedHeaders, hash('sha256', $body)]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return [...$headers, 'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}"];
    }
}

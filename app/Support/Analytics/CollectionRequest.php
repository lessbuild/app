<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The tracker endpoint's request rules, shared by the collect and preflight controllers. */
final class CollectionRequest
{
    public static function originIsAllowed(?string $origin, AnalyticsSite $site): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && collect($site->domains)->contains(fn (string $domain): bool => strcasecmp($domain, $host) === 0);
    }

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent !== null && preg_match('/bot|crawler|spider|slurp|bingpreview|headless/i', $userAgent) === 1;
    }

    public static function cleanHost(mixed $host): ?string
    {
        $host = trim(is_string($host) ? $host : '');

        return $host !== '' && preg_match('/^[a-z0-9.-]+$/i', $host) ? strtolower(Str::limit($host, 255, '')) : null;
    }

    public static function cleanValue(mixed $value, int $length): ?string
    {
        $value = trim(is_string($value) ? $value : '');

        return $value !== '' ? Str::limit($value, $length, '') : null;
    }

    /**
     * Custom events may carry a name only; nothing else a site sends is stored.
     *
     * @return array{name?: string}
     */
    public static function safeProperties(mixed $properties): array
    {
        $name = is_array($properties) ? ($properties['name'] ?? null) : null;

        return is_string($name) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/i', $name) === 1 ? ['name' => $name] : [];
    }

    /** @return array<string, string> */
    public static function corsHeaders(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => $request->header('Origin') ?: '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}

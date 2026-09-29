<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The tracker endpoint's request rules, shared by the collect and preflight controllers. */
final class CollectionRequest
{
    /**
     * Determine whether a collection request may be counted for this site: its `Origin` must be one of the site's
     * domains. Requests without an Origin (server-side or no-cors beacons) are allowed, since browsers always send one
     * from pages.
     *
     * @param  string|null  $origin
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public static function originIsAllowed(?string $origin, AnalyticsSite $site): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && collect($site->domains)->contains(fn (string $domain): bool => strcasecmp($domain, $host) === 0);
    }

    /**
     * Determine whether the user agent is a crawler or headless browser, cheaply, to keep them out of the numbers.
     *
     * @param  string|null  $userAgent
     * @return bool
     */
    public static function isBot(?string $userAgent): bool
    {
        return $userAgent !== null && preg_match('/bot|crawler|spider|slurp|bingpreview|headless/i', $userAgent) === 1;
    }

    /**
     * Clean the hostname a pageview reported: lowercased and cut to 255 characters, or null when it's empty or has
     * characters a hostname can't.
     *
     * @param  mixed  $host
     * @return string|null
     */
    public static function cleanHost(mixed $host): ?string
    {
        $host = trim(is_string($host) ? $host : '');

        return $host !== '' && preg_match('/^[a-z0-9.-]+$/i', $host) ? strtolower(Str::limit($host, 255, '')) : null;
    }

    /**
     * Trim an untrusted string field and cuts it to the column's length; empty values become null.
     *
     * @param  mixed  $value
     * @param  int  $length
     * @return string|null
     */
    public static function cleanValue(mixed $value, int $length): ?string
    {
        $value = trim(is_string($value) ? $value : '');

        return $value !== '' ? Str::limit($value, $length, '') : null;
    }

    /**
     * The automatic events the tracker can send, and the one detail each may carry: the link's host and path, or the
     * file's path. Nothing else a site sends is stored.
     *
     * @var array<string, string>
     */
    public const AUTOMATIC_EVENTS = ['outbound_link' => 'url', 'file_download' => 'file'];

    /**
     * Keep a custom event's name, its revenue (an amount up to a billion with two decimals, and a three-letter
     * currency, USD when none is given) and, for the tracker's automatic events, their link or file (host and path
     * only, without query strings); nothing else a site sends is stored.
     *
     * @param  mixed  $properties
     * @return array{name?: string, url?: string, file?: string, revenue?: float, currency?: string}
     */
    public static function safeProperties(mixed $properties): array
    {
        $name = is_array($properties) ? ($properties['name'] ?? null) : null;
        if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/i', $name) !== 1) {
            return [];
        }
        $kept = ['name' => $name];
        $detail = self::AUTOMATIC_EVENTS[$name] ?? null;
        $value = $detail !== null ? ($properties[$detail] ?? null) : null;
        if (is_string($value)) {
            $value = Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]|[?#].*$/u', '', $value)), 500, '');
            if ($value !== '') {
                $kept[$detail] = $value;
            }
        }
        $revenue = $properties['revenue'] ?? null;
        if (is_string($revenue) && is_numeric($revenue)) {
            $revenue = (float) $revenue;
        }
        if ((is_int($revenue) || is_float($revenue)) && $revenue >= 0 && $revenue <= 1_000_000_000) {
            $kept['revenue'] = round((float) $revenue, 2);
            $currency = strtoupper(is_string($properties['currency'] ?? null) ? trim($properties['currency']) : '');
            $kept['currency'] = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
        }

        return $kept;
    }

    /**
     * Keep a page-speed (Web Vitals) event's measurements: LCP, INP and TTFB in whole milliseconds up to ten minutes,
     * and CLS as a score up to 100. Anything else is dropped.
     *
     * @param  mixed  $properties
     * @return array<string, int|float>
     */
    public static function safeVitals(mixed $properties): array
    {
        $kept = [];
        foreach (['lcp' => 600000, 'inp' => 600000, 'ttfb' => 600000, 'cls' => 100] as $metric => $max) {
            $value = is_array($properties) ? ($properties[$metric] ?? null) : null;
            if ((is_int($value) || is_float($value)) && $value >= 0 && $value <= $max) {
                $kept[$metric] = $metric === 'cls' ? round((float) $value, 4) : (int) round((float) $value);
            }
        }

        return $kept;
    }

    /**
     * Build the CORS headers for the collection endpoint. They echo the caller's Origin because the tracker runs on
     * customers' own domains; the origin is checked against the site separately.
     *
     * @param  Request  $request
     * @return array<string, string>
     */
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

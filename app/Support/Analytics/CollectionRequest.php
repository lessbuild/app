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
     * currency, USD when none is given), for the tracker's automatic events their link or file (host and path only,
     * without query strings), up to 20 purchased items (id, name, category, price and quantity), and the custom
     * properties the site has chosen to keep (text, number or yes/no values cut to 100 characters, never anything
     * that looks like an email address) for breakdowns.
     *
     * @param  mixed  $properties
     * @param  list<string>  $customKeys  the property names the site keeps
     * @return array{name?: string, term?: string, results?: int, url?: string, file?: string, revenue?: float, currency?: string, items?: list<array{id: string|null, name: string|null, category: string|null, price: float|null, quantity: int}>, props?: array<string, string>}
     */
    public static function safeProperties(mixed $properties, array $customKeys = []): array
    {
        $name = is_array($properties) ? ($properties['name'] ?? null) : null;
        if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/i', $name) !== 1) {
            return [];
        }
        $kept = ['name' => $name];
        if ($name === 'search') {
            $term = self::searchTerm($properties['term'] ?? null);
            $results = $properties['results'] ?? null;

            return array_filter(['name' => 'search', 'term' => $term, 'results' => is_int($results) && $results >= 0 ? min($results, 1_000_000) : null], fn (mixed $value): bool => $value !== null);
        }
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
        $items = self::safeItems($properties['items'] ?? null);
        if ($items !== []) {
            $kept['items'] = $items;
        }
        $custom = [];
        foreach ($properties as $key => $value) {
            if (count($custom) >= 10 || ! is_string($key) || ! in_array($key, $customKeys, true) || in_array($key, ['name', 'url', 'file', 'revenue', 'currency', 'items'], true)) {
                continue;
            }
            $text = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value)),
                default => '',
            };
            if ($text !== '' && preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text) !== 1) {
                $custom[$key] = Str::limit($text, 100, '');
            }
        }
        if ($custom !== []) {
            $kept['props'] = $custom;
        }

        return $kept;
    }

    /**
     * Keep up to 20 purchased items from an e-commerce event, each with an id or name, an optional category, a price
     * up to a million and a quantity from 1 to 10,000 (1 when missing).
     *
     * @param  mixed  $items
     * @return list<array{id: string|null, name: string|null, category: string|null, price: float|null, quantity: int}>
     */
    private static function safeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $kept = [];
        foreach (array_slice(array_values($items), 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = self::cleanValue(is_scalar($item['id'] ?? null) ? (string) $item['id'] : null, 64);
            $name = self::cleanValue($item['name'] ?? null, 100);
            if ($id === null && $name === null) {
                continue;
            }
            $price = $item['price'] ?? null;
            $price = is_string($price) && is_numeric($price) ? (float) $price : $price;
            $quantity = $item['quantity'] ?? 1;
            $kept[] = [
                'id' => $id,
                'name' => $name,
                'category' => self::cleanValue($item['category'] ?? null, 64),
                'price' => (is_int($price) || is_float($price)) && $price >= 0 && $price <= 1_000_000 ? round((float) $price, 2) : null,
                'quantity' => is_int($quantity) && $quantity >= 1 && $quantity <= 10_000 ? $quantity : 1,
            ];
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
     * Clean an on-site search term: trimmed, lowercased, cut to 100 characters, and dropped when it looks like an
     * email address (people paste those into search boxes).
     *
     * @param  mixed  $term
     * @return string|null
     */
    public static function searchTerm(mixed $term): ?string
    {
        $term = is_string($term) ? mb_strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $term))) : '';

        return $term === '' || preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $term) === 1 ? null : mb_substr($term, 0, 100);
    }

    /**
     * Keep a click's position (percent of the page's width and height) and a short description of what was clicked.
     * Null when it isn't usable.
     *
     * @param  mixed  $properties
     * @return array{target: string, x: float, y: float}|null
     */
    public static function safeClick(mixed $properties): ?array
    {
        $target = is_array($properties) ? self::cleanValue($properties['target'] ?? null, 80) : null;
        $x = is_array($properties) ? ($properties['x'] ?? null) : null;
        $y = is_array($properties) ? ($properties['y'] ?? null) : null;
        if ($target === null || ! is_numeric($x) || ! is_numeric($y)) {
            return null;
        }

        return ['target' => $target, 'x' => round(max(0, min(100, (float) $x)), 1), 'y' => round(max(0, min(100, (float) $y)), 1)];
    }

    /**
     * Keep a form event: the form's name, and for focus events the field's name. Never a value.
     *
     * @param  mixed  $properties
     * @return array{form: string, action: string, field?: string}|null
     */
    public static function safeForm(mixed $properties): ?array
    {
        $form = is_array($properties) ? self::cleanValue($properties['form'] ?? null, 80) : null;
        $action = is_array($properties) ? ($properties['action'] ?? null) : null;
        $field = is_array($properties) ? self::cleanValue($properties['field'] ?? null, 80) : null;
        if ($form === null || ! in_array($action, ['focus', 'submit'], true) || ($action === 'focus' && $field === null)) {
            return null;
        }

        return $action === 'focus' ? ['form' => $form, 'action' => 'focus', 'field' => (string) $field] : ['form' => $form, 'action' => 'submit'];
    }

    /**
     * Keep an engagement event's measurements: how far down the page the visitor scrolled (0–100%) and how long the
     * page was visible, in whole milliseconds up to 30 minutes. Null when neither is usable.
     *
     * @param  mixed  $properties
     * @return array{scroll?: int, engaged_ms?: int}|null
     */
    public static function safeEngagement(mixed $properties): ?array
    {
        $kept = [];
        foreach (['scroll' => 100, 'engaged_ms' => 1_800_000] as $key => $max) {
            $value = is_array($properties) ? ($properties[$key] ?? null) : null;
            if ((is_int($value) || is_float($value)) && $value >= 0) {
                $kept[$key] = (int) min($max, round((float) $value));
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * Group a screen width in CSS pixels into Mobile (under 576), Tablet (under 992), Laptop (under 1440) or Desktop.
     *
     * @param  mixed  $width
     * @return string|null
     */
    public static function screenSize(mixed $width): ?string
    {
        if (! is_int($width) || $width <= 0) {
            return null;
        }

        return match (true) {
            $width < 576 => 'Mobile',
            $width < 992 => 'Tablet',
            $width < 1440 => 'Laptop',
            default => 'Desktop',
        };
    }

    /**
     * Keep a version number: digits and dots only, up to three parts ("128", "17.4", "10.15").
     *
     * @param  mixed  $version
     * @return string|null
     */
    public static function version(mixed $version): ?string
    {
        $version = is_string($version) || is_int($version) ? (string) $version : '';

        return preg_match('/\A\d{1,5}(\.\d{1,5}){0,2}\z/', $version) === 1 ? $version : null;
    }

    /**
     * Get the visitor's address: the first public address in X-Forwarded-For when a site sends events through its
     * own proxy, otherwise the connecting address. A forged header only changes how the sender's own visits are
     * counted.
     *
     * @param  Request  $request
     * @return string|null
     */
    public static function clientIp(Request $request): ?string
    {
        foreach (explode(',', (string) $request->headers->get('X-Forwarded-For', '')) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $candidate;
            }
        }

        return $request->ip();
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

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Exceptions\UrlGenerationException;

/**
 * The list pages whose filters can be saved: the route each opens, the route parameters it needs, and the query keys
 * it keeps. Anything else in a request is dropped, so a saved view can only ever reopen the page it came from.
 */
final class SavedViewPages
{
    /**
     * Each page's route, required route parameters and filter keys.
     *
     * @var array<string, array{route: string, parameters: list<string>, keys: list<string>}>
     */
    public const PAGES = [
        'analytics.overview' => ['route' => 'analytics.overview', 'parameters' => ['project'], 'keys' => ['site', 'days', 'from', 'to', 'compare', 'path', 'source', 'campaign', 'country', 'device', 'browser', 'os']],
        'audit-log' => ['route' => 'account.audit-log', 'parameters' => [], 'keys' => ['project', 'person', 'category', 'from', 'to']],
        'notifications' => ['route' => 'notifications.index', 'parameters' => [], 'keys' => ['filter', 'type', 'q']],
        'monitoring.events' => ['route' => 'monitoring.events', 'parameters' => ['project'], 'keys' => ['environment', 'q', 'range', 'severity', 'sort', 'trace', 'type']],
        'monitoring.issues' => ['route' => 'monitoring.issues', 'parameters' => ['project'], 'keys' => ['environment', 'ownership', 'q', 'status']],
        'monitoring.metrics' => ['route' => 'monitoring.metrics', 'parameters' => ['project'], 'keys' => ['environment', 'kind', 'q']],
    ];

    /**
     * Determine whether a page's filters can be saved.
     *
     * @param  string  $page
     * @return bool
     */
    public static function exists(string $page): bool
    {
        return array_key_exists($page, self::PAGES);
    }

    /**
     * Keep only the filter keys a page allows, with non-empty short string values.
     *
     * @param  string  $page
     * @param  array<array-key, mixed>  $query
     * @return array<string, string>
     */
    public static function query(string $page, array $query): array
    {
        return self::only(self::PAGES[$page]['keys'] ?? [], $query);
    }

    /**
     * Keep only the route parameters a page needs.
     *
     * @param  string  $page
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string>
     */
    public static function parameters(string $page, array $parameters): array
    {
        return self::only(self::PAGES[$page]['parameters'] ?? [], $parameters);
    }

    /**
     * Build the address a saved view opens, or null when its page no longer resolves.
     *
     * @param  string  $page
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $query
     * @return string|null
     */
    public static function url(string $page, array $parameters, array $query): ?string
    {
        if (! self::exists($page)) {
            return null;
        }
        try {
            return route(self::PAGES[$page]['route'], [...$parameters, ...$query]);
        } catch (UrlGenerationException) {
            return null;
        }
    }

    /**
     * Pick the allowed keys with non-empty short string values.
     *
     * @param  list<string>  $keys
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function only(array $keys, array $values): array
    {
        $kept = [];
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_scalar($value) && ($value = trim((string) $value)) !== '' && mb_strlen($value) <= 200) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}

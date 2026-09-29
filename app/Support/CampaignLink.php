<?php

declare(strict_types=1);

namespace App\Support;

/** Builds links tagged with UTM campaign parameters, which Analytics reads into its campaign and source reports. */
final class CampaignLink
{
    /**
     * The UTM parameters, in the order they're added, with their labels.
     *
     * @var array<string, string>
     */
    public const PARAMETERS = [
        'utm_source' => 'Source',
        'utm_medium' => 'Medium',
        'utm_campaign' => 'Campaign',
        'utm_term' => 'Term',
        'utm_content' => 'Content',
    ];

    /**
     * Add the non-empty UTM parameters to an http(s) URL, replacing any it already has and keeping its other query
     * parameters and fragment. Returns null when the URL isn't an http(s) address.
     *
     * @param  string  $url
     * @param  array<string, string|null>  $parameters  keyed by PARAMETERS
     * @return string|null
     */
    public static function build(string $url, array $parameters): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys(self::PARAMETERS) as $key) {
            unset($query[$key]);
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = mb_substr($value, 0, 200);
            }
        }
        $base = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $base.($queryString !== '' ? '?'.$queryString : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}

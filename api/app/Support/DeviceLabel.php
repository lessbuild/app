<?php

declare(strict_types=1);

namespace App\Support;

/** A short, human label such as "Firefox on macOS" from a user agent. Good enough to recognise your own devices. */
final class DeviceLabel
{
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Mac OS X' => 'macOS',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    /**
     * Describe a browser session briefly ("Firefox on macOS") for the sessions and sign-in activity lists. It only
     * needs to be recognisable, so it checks a few well-known markers instead of parsing the user agent fully.
     *
     * @param  string|null  $userAgent
     * @return string
     */
    public static function from(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return __('Unknown device');
        }

        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
            default => $browser ?? $system ?? __('Unknown device'),
        };
    }

    /**
     * Find the label of the first needle found in the haystack. Order matters: Edge and Opera also claim to be Chrome
     * and Safari, so they're listed first.
     *
     * @param  array<string, string>  $needles
     * @param  string  $haystack
     * @return string|null
     */
    private static function first(array $needles, string $haystack): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }
}

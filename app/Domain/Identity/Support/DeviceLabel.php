<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

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

    /** @param array<string, string> $needles */
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

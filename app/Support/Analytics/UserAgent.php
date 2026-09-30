<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Reads the device, browser and operating system from a User-Agent header, the same way the tracker does in the
 * browser, for events sent from a server.
 */
final class UserAgent
{
    /**
     * Describe a User-Agent.
     *
     * @param  string  $userAgent
     * @return array{device: string, browser: string, browser_version: string|null, os: string, os_version: string|null}
     */
    public static function parse(string $userAgent): array
    {
        $browser = match (true) {
            preg_match('/edg(e|a|ios)?\//i', $userAgent) === 1 => 'Edge',
            preg_match('/opr\/|opera/i', $userAgent) === 1 => 'Opera',
            preg_match('/samsungbrowser/i', $userAgent) === 1 => 'Samsung Internet',
            preg_match('/yabrowser/i', $userAgent) === 1 => 'Yandex',
            preg_match('/vivaldi/i', $userAgent) === 1 => 'Vivaldi',
            preg_match('/duckduckgo/i', $userAgent) === 1 => 'DuckDuckGo',
            preg_match('/firefox|fxios/i', $userAgent) === 1 => 'Firefox',
            preg_match('/chrome|crios|chromium/i', $userAgent) === 1 => 'Chrome',
            preg_match('/safari/i', $userAgent) === 1 => 'Safari',
            default => 'Other',
        };
        $browserVersion = preg_match('/(?:edg(?:e|a|ios)?|opr|samsungbrowser|yabrowser|vivaldi|firefox|fxios|crios|chrome)\/(\d+)/i', $userAgent, $match) === 1
            ? $match[1] : (preg_match('/version\/(\d+(?:\.\d+)?).*safari/i', $userAgent, $match) === 1 ? $match[1] : null);
        $os = match (true) {
            preg_match('/windows/i', $userAgent) === 1 => 'Windows',
            preg_match('/iphone|ipad|ipod/i', $userAgent) === 1 => 'iOS',
            preg_match('/mac os|macintosh/i', $userAgent) === 1 => 'macOS',
            preg_match('/android/i', $userAgent) === 1 => 'Android',
            preg_match('/cros/i', $userAgent) === 1 => 'ChromeOS',
            preg_match('/linux/i', $userAgent) === 1 => 'Linux',
            default => 'Other',
        };
        $osVersion = match (true) {
            preg_match('/(?:iphone|cpu) os (\d+)[_.](\d+)/i', $userAgent, $match) === 1 => $match[1].'.'.$match[2],
            preg_match('/android (\d+(?:\.\d+)?)/i', $userAgent, $match) === 1 => $match[1],
            preg_match('/windows nt (\d+\.\d+)/i', $userAgent, $match) === 1 => $match[1],
            preg_match('/mac os x (\d+)[_.](\d+)/i', $userAgent, $match) === 1 => $match[1].'.'.$match[2],
            default => null,
        };
        $device = preg_match('/tablet|ipad/i', $userAgent) === 1 ? 'Tablet' : (preg_match('/mobile|iphone|android/i', $userAgent) === 1 ? 'Mobile' : 'Desktop');

        return ['device' => $device, 'browser' => $browser, 'browser_version' => $browserVersion, 'os' => $os, 'os_version' => $osVersion];
    }
}

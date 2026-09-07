<?php

namespace App\Services;

use Illuminate\Support\Str;

class ClientMetadata
{
    /**
     * Describe a browser and platform from a client-supplied user-agent string.
     *
     * @param  string|null  $userAgent  The optional raw user-agent header.
     * @return string A localized browser/device label with unknown-value fallbacks.
     */
    public function deviceName(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return __('Unknown browser or device');
        }

        $agent = strtolower($userAgent);
        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'firefox/'), str_contains($agent, 'fxios/') => 'Firefox',
            str_contains($agent, 'chrome/'), str_contains($agent, 'crios/') => 'Chrome',
            str_contains($agent, 'safari/') => 'Safari',
            default => __('Unknown browser'),
        };
        $platform = match (true) {
            str_contains($agent, 'iphone') => 'iPhone',
            str_contains($agent, 'ipad') => 'iPad',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os x') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => __('Unknown device'),
        };

        return __(':browser on :platform', compact('browser', 'platform'));
    }

    /**
     * Validate and trim an IPv4 or IPv6 address for session metadata.
     *
     * @param  string|null  $ipAddress  The optional client address to normalize.
     * @return string|null The trimmed valid address, or null for absent or invalid input.
     */
    public function normalizedIp(?string $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);

        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? $ipAddress
            : null;
    }

    /**
     * Provide a safe display label for an optional client address.
     *
     * @param  string|null  $ipAddress  The client address to validate.
     * @return string The valid address, or the localized unknown-address label.
     */
    public function displayIp(?string $ipAddress): string
    {
        return $this->normalizedIp($ipAddress) ?? __('Unknown IP address');
    }

    /**
     * Bound stored user-agent metadata after trimming surrounding whitespace.
     *
     * @param  string|null  $userAgent  The optional raw user-agent string.
     * @return string|null At most 500 characters, or null for blank input.
     */
    public function normalizedUserAgent(?string $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);

        return $userAgent === '' ? null : Str::limit($userAgent, 500, '');
    }
}

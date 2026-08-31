<?php

namespace App\Services;

use Illuminate\Support\Str;

class ClientMetadata
{
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

    public function normalizedIp(?string $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);

        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? $ipAddress
            : null;
    }

    public function displayIp(?string $ipAddress): string
    {
        return $this->normalizedIp($ipAddress) ?? __('Unknown IP address');
    }

    public function normalizedUserAgent(?string $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);

        return $userAgent === '' ? null : Str::limit($userAgent, 500, '');
    }
}

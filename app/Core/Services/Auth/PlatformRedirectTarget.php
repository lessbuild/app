<?php

namespace App\Core\Services\Auth;

use Illuminate\Http\Request;

/** Resolves post-authentication destinations against explicitly configured application origins. */
final class PlatformRedirectTarget
{
    public function resolve(?string $target, Request $request): ?string
    {
        if (! is_string($target) || trim($target) === '' || preg_match('/[\\\\\r\n]/', $target)) {
            return null;
        }

        $target = trim($target);
        $parts = parse_url($target);

        if (! is_array($parts) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            return null;
        }

        if (! isset($parts['host'])) {
            return str_starts_with($target, '/') && ! str_starts_with($target, '//')
                ? $target
                : null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($scheme !== 'https'
            && (! in_array(app()->environment(), ['local', 'testing'], true) || $request->isSecure())) {
            return null;
        }

        $origin = $scheme.'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return in_array($origin, $this->configuredOrigins(), true) ? $target : null;
    }

    /** @return list<string> */
    private function configuredOrigins(): array
    {
        $configured = [
            config('app.url'),
            config('platform.dashboard_url'),
            config('platform.auth_url'),
            config('platform.dashboard_host'),
            config('platform.auth_host'),
        ];

        foreach (config('platform.products', []) as $product) {
            $configured[] = $product['url'] ?? null;
            $configured[] = $product['host'] ?? null;
        }

        $origins = [];
        foreach ($configured as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            $parts = parse_url(str_contains($value, '://') ? $value : 'https://'.$value);
            if (! is_array($parts) || ! isset($parts['host'])) {
                continue;
            }

            $origin = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower($parts['host']);
            if (isset($parts['port'])) {
                $origin .= ':'.$parts['port'];
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }
}

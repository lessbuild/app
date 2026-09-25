<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;

/** Allow only authenticated Analytics billing routes on the configured product origin. */
final class AnalyticsBillingReturnTarget
{
    public function assertAllowed(string $url, AnalyticsWorkspace $workspace, string $kind): void
    {
        $parts = parse_url($url);
        $configuredBase = config('platform.products.analytics.url');
        $baseParts = is_string($configuredBase) ? parse_url($configuredBase) : false;
        $workspaceId = (string) $workspace->getKey();
        $expectedPath = match ($kind) {
            'success' => '/workspaces/'.$workspaceId.'/billing/success',
            'cancel' => '/workspaces/'.$workspaceId.'/billing/canceled',
            'portal' => '/workspaces/'.$workspaceId.'/billing',
            default => null,
        };

        if (! is_array($parts) || ! is_array($baseParts) || $expectedPath === null
            || ! isset($parts['scheme'], $parts['host'], $baseParts['scheme'], $baseParts['host'])
            || $parts['scheme'] !== 'https' || $baseParts['scheme'] !== 'https'
            || isset($baseParts['user']) || isset($baseParts['pass'])
            || isset($baseParts['query']) || isset($baseParts['fragment'])
            || ! in_array($baseParts['path'] ?? '', ['', '/'], true)
            || $this->origin($parts) !== $this->origin($baseParts)
            || ($parts['path'] ?? '/') !== $expectedPath
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new AnalyticsBillingException('The Analytics billing return target is not allowed.');
        }

        $query = $parts['query'] ?? '';
        $validQuery = $kind === 'success'
            ? $query === 'session_id={CHECKOUT_SESSION_ID}'
            : $query === '';

        if (! $validQuery) {
            throw new AnalyticsBillingException('The Analytics billing return target is not allowed.');
        }
    }

    /** @param array<string, mixed> $parts */
    private function origin(array $parts): ?string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $origin = $scheme.'://'.$host;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}

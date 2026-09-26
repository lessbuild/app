<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\DnsResolver;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use Symfony\Component\HttpFoundation\IpUtils;

final class PublicWebhookTarget
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function host(string $url, AlertDestinationType $type): ?string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F-\xFF\\\\]/', $url) || ! str_starts_with($url, 'https://')) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['port'] ?? 443) !== 443) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D', $host)) {
            return null;
        }
        if ($type === AlertDestinationType::Slack && ($host !== 'hooks.slack.com'
            || isset($parts['query']) || ! preg_match('~^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$~D', $parts['path'] ?? ''))) {
            return null;
        }
        if ($type === AlertDestinationType::Teams && ((! in_array($host, ['outlook.office.com', 'webhook.office.com'], true)
            && ! str_ends_with($host, '.webhook.office.com')) || isset($parts['query'])
            || ! preg_match('~^/(?:webhook|webhookb2)/.+$~D', $parts['path'] ?? ''))) {
            return null;
        }
        if ($type === AlertDestinationType::PagerDuty && ($host !== 'events.pagerduty.com'
            || ($parts['path'] ?? '') !== '/v2/enqueue' || isset($parts['query']))) {
            return null;
        }
        if ($type === AlertDestinationType::Discord && ($host !== 'discord.com'
            || isset($parts['query'])
            || ! preg_match('~^/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$~D', $parts['path'] ?? ''))) {
            return null;
        }

        return $host;
    }

    /** @return array{host: ?string, address: ?string, error: ?string} */
    public function resolve(string $url, AlertDestinationType $type): array
    {
        $host = $this->host($url, $type);
        if ($host === null) {
            return ['host' => null, 'address' => null, 'error' => 'target_invalid'];
        }
        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            return ['host' => $host, 'address' => null, 'error' => 'dns_unavailable'];
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return ['host' => $host, 'address' => null, 'error' => 'target_not_public'];
            }
        }

        return ['host' => $host, 'address' => $addresses[0], 'error' => null];
    }

    public function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! IpUtils::checkIp($address, [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ]);
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && IpUtils::checkIp($address, '2000::/3')
            && ! IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
    }
}

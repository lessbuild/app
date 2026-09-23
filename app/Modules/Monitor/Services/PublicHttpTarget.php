<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\DnsResolver;

final class PublicHttpTarget
{
    public function __construct(private readonly DnsResolver $dns, private readonly PublicWebhookTarget $addresses) {}

    /** @return array{host: string, port: int, scheme: string, literal: bool}|null */
    public function parse(string $url): ?array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F-\xFF\\\\]/', $url)
            || ! preg_match('~^https?://~D', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $address = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        $literal = filter_var($address, FILTER_VALIDATE_IP) !== false;
        if ($literal ? ! $this->addresses->isPublic($address)
            : (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D', $host))) {
            return null;
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['host' => $literal ? $address : $host, 'port' => $port, 'scheme' => $parts['scheme'], 'literal' => $literal];
    }

    /** @return array{host?: string, port?: int, scheme?: string, literal?: bool, address?: string, error: ?string} */
    public function resolve(string $url): array
    {
        $target = $this->parse($url);
        if ($target === null) {
            return ['error' => 'target_invalid'];
        }
        $addresses = $target['literal'] ? [$target['host']] : $this->dns->addresses($target['host']);
        if ($addresses === []) {
            return ['error' => 'dns_unavailable'];
        }
        foreach ($addresses as $address) {
            if (! $this->addresses->isPublic($address)) {
                return ['error' => 'target_not_public'];
            }
        }

        return [...$target, 'address' => $addresses[0], 'error' => null];
    }
}

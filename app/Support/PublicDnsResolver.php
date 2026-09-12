<?php

namespace App\Support;

class PublicDnsResolver
{
    /**
     * Resolve A and AAAA records for a configured external endpoint.
     *
     * @return list<string> The resolved IPv4 and IPv6 address literals.
     */
    public function addresses(string $host): array
    {
        return collect(dns_get_record($host, DNS_A | DNS_AAAA) ?: [])
            ->map(fn (array $record): mixed => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter(fn (mixed $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();
    }
}

<?php

namespace App\Services;

use App\Models\WebsiteDomain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareDns
{
    public function sync(WebsiteDomain $domain): void
    {
        $domain->loadMissing(['dnsProvider', 'website.server']);
        $provider = $domain->dnsProvider;
        $address = $domain->website->server?->public_ip;
        if (! $provider || blank($provider->token) || blank($address)) {
            throw new RuntimeException('Cloudflare DNS requires a credential and an attached server address.');
        }

        [$zoneId] = $this->zone($provider->token, $domain->hostname);
        $payload = [
            'type' => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
            'name' => $domain->hostname,
            'content' => $address,
            'ttl' => 1,
            'proxied' => false,
            'comment' => 'Managed by BuildPusher',
        ];
        $existing = $this->recordReference($domain->dns_record_id);
        $response = $existing && $existing[0] === $zoneId
            ? $this->client($provider->token)->put("/zones/{$zoneId}/dns_records/{$existing[1]}", $payload)
            : $this->client($provider->token)->post("/zones/{$zoneId}/dns_records", $payload);
        $result = $response->throw()->json('result');
        if (! is_array($result) || blank($result['id'] ?? null)) {
            throw new RuntimeException('Cloudflare did not return a DNS record identifier.');
        }

        $domain->forceFill([
            'dns_record_id' => $zoneId.':'.$result['id'],
            'dns_status' => 'active',
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function delete(WebsiteDomain $domain): void
    {
        $reference = $this->recordReference($domain->dns_record_id);
        if (! $reference || ! $domain->dnsProvider || blank($domain->dnsProvider->token)) {
            return;
        }

        $this->client($domain->dnsProvider->token)
            ->delete("/zones/{$reference[0]}/dns_records/{$reference[1]}")
            ->throw();
    }

    /** @return array{string, string} */
    private function zone(string $token, string $hostname): array
    {
        $zones = $this->client($token)->get('/zones', ['per_page' => 50, 'status' => 'active'])->throw()->json('result');
        $matches = collect(is_array($zones) ? $zones : [])->filter(function (array $zone) use ($hostname): bool {
            $name = strtolower((string) ($zone['name'] ?? ''));

            return $name !== '' && ($hostname === $name || str_ends_with($hostname, '.'.$name));
        })->sortByDesc(fn (array $zone): int => strlen((string) $zone['name']));
        $zone = $matches->first();
        if (! is_array($zone) || blank($zone['id'] ?? null)) {
            throw new RuntimeException('No matching active Cloudflare zone is available to this token.');
        }

        return [(string) $zone['id'], (string) $zone['name']];
    }

    /** @return null|array{string, string} */
    private function recordReference(?string $reference): ?array
    {
        if (! is_string($reference) || ! preg_match('/\A([a-zA-Z0-9_-]+):([a-zA-Z0-9_-]+)\z/', $reference, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('domains.cloudflare_api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }
}

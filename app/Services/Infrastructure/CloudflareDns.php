<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\WebsiteDomain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Keeps a domain's A/AAAA record at Cloudflare pointing at its website's server (not proxied), in the longest matching zone. */
class CloudflareDns
{
    /**
     * Creates or updates the domain's A or AAAA record in the most specific Cloudflare zone the token can see,
     * unproxied, and remembers the zone and record IDs.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function sync(WebsiteDomain $domain): void
    {
        $domain->loadMissing(['dnsProvider', 'website.server']);
        $token = $domain->dnsProvider?->token;
        $address = $domain->website->server?->public_ip;
        if ($token === null || $token === '' || $address === null || $address === '') {
            throw new RuntimeException('Cloudflare DNS needs a credential and a server address.');
        }
        $zoneId = $this->zone($token, $domain->hostname);
        $payload = [
            'type' => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A',
            'name' => $domain->hostname, 'content' => $address, 'ttl' => 1, 'proxied' => false, 'comment' => 'Managed by '.config('app.name'),
        ];
        $existing = $this->reference($domain->dns_record_id);
        $response = $existing !== null && $existing[0] === $zoneId
            ? $this->client($token)->put("/zones/{$zoneId}/dns_records/{$existing[1]}", $payload)
            : $this->client($token)->post("/zones/{$zoneId}/dns_records", $payload);
        $id = $response->throw()->json('result.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Cloudflare didn’t return a DNS record ID.');
        }
        $domain->forceFill(['dns_record_id' => "{$zoneId}:{$id}", 'dns_status' => 'active', 'last_checked_at' => CarbonImmutable::now('UTC'), 'last_error' => null])->save();
    }

    /**
     * Deletes the domain's record at Cloudflare, if we created one.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function delete(WebsiteDomain $domain): void
    {
        $reference = $this->reference($domain->dns_record_id);
        $token = $domain->dnsProvider?->token;
        if ($reference === null || $token === null || $token === '') {
            return;
        }
        $this->client($token)->delete("/zones/{$reference[0]}/dns_records/{$reference[1]}")->throw();
    }

    /**
     * The ID of the longest active zone the hostname falls in.
     *
     * @param  string  $token
     * @param  string  $hostname
     * @return string
     */
    private function zone(string $token, string $hostname): string
    {
        $zones = $this->client($token)->get('/zones', ['per_page' => 50, 'status' => 'active'])->throw()->json('result');
        $best = null;
        foreach (is_array($zones) ? $zones : [] as $zone) {
            $name = is_array($zone) ? strtolower((string) ($zone['name'] ?? '')) : '';
            if ($name !== '' && ($hostname === $name || str_ends_with($hostname, '.'.$name)) && ($best === null || strlen($name) > strlen($best['name']))) {
                $best = ['id' => (string) ($zone['id'] ?? ''), 'name' => $name];
            }
        }
        if ($best === null || $best['id'] === '') {
            throw new RuntimeException('No active Cloudflare zone matching this hostname is available to the token.');
        }

        return $best['id'];
    }

    /**
     * The zone and record IDs from a stored `zone:record` reference.
     *
     * @param  string|null  $reference
     * @return array{string, string}|null
     */
    private function reference(?string $reference): ?array
    {
        return is_string($reference) && preg_match('/\A([a-zA-Z0-9_-]+):([a-zA-Z0-9_-]+)\z/', $reference, $match) === 1 ? [$match[1], $match[2]] : null;
    }

    /**
     * An HTTP client for the Cloudflare API with the token, short timeouts and two quick retries.
     *
     * @param  string  $token
     * @return PendingRequest
     */
    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('infrastructure.cloudflare_api_url'), '/'))->acceptJson()->asJson()->withToken($token)
            ->connectTimeout(5)->timeout(15)->retry(2, 200, throw: false);
    }
}

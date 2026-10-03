<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\WebsiteDomain;
use App\Support\AwsSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Manages a domain's A or AAAA record at DigitalOcean DNS, Hetzner DNS or AWS Route 53, in the most specific zone the
 * credential can see, remembering "zone:record" so it can be updated or removed later.
 */
final class OtherDns
{
    /**
     * Create or update the domain's record pointing at its website's server.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function sync(WebsiteDomain $domain): void
    {
        $domain->loadMissing(['dnsProvider', 'website.server']);
        $provider = $domain->dnsProvider;
        $address = $domain->website->server?->public_ip;
        if ($provider === null || $provider->token === '' || $address === null || $address === '') {
            throw new RuntimeException('DNS needs a credential and a server address.');
        }
        $type = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A';
        $reference = match ($provider->type) {
            ProviderType::DigitalOcean => $this->digitalOcean($provider->token, $domain, $type, $address),
            ProviderType::HetznerDns => $this->hetzner($provider->token, $domain, $type, $address),
            ProviderType::Route53 => $this->route53($provider->token, $domain, $type, $address, 'UPSERT'),
            default => throw new RuntimeException('That provider doesn’t manage DNS here.'),
        };
        $domain->forceFill(['dns_record_id' => $reference, 'dns_status' => 'active', 'last_checked_at' => CarbonImmutable::now('UTC'), 'last_error' => null])->save();
    }

    /**
     * Delete the domain's record, if we created one.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function delete(WebsiteDomain $domain): void
    {
        $domain->loadMissing(['dnsProvider', 'website.server']);
        $provider = $domain->dnsProvider;
        [$zone, $record] = array_pad(explode(':', (string) $domain->dns_record_id, 2), 2, '');
        if ($provider === null || $zone === '' || $record === '') {
            return;
        }
        match ($provider->type) {
            ProviderType::DigitalOcean => $this->digitalOceanClient($provider->token)->delete("/domains/{$zone}/records/{$record}")->throw(),
            ProviderType::HetznerDns => $this->hetznerClient($provider->token)->delete("/records/{$record}")->throw(),
            ProviderType::Route53 => $domain->website->server?->public_ip !== null
                ? $this->route53($provider->token, $domain, $record, (string) $domain->website->server->public_ip, 'DELETE') : null,
            default => null,
        };
    }

    /**
     * Create or update the record at DigitalOcean DNS; the zone is the domain name there.
     *
     * @param  string  $token
     * @param  WebsiteDomain  $domain
     * @param  string  $type
     * @param  string  $address
     * @return string
     */
    private function digitalOcean(string $token, WebsiteDomain $domain, string $type, string $address): string
    {
        $client = $this->digitalOceanClient($token);
        $zones = array_map(fn (array $zone): string => strtolower((string) ($zone['name'] ?? '')), (array) $client->get('/domains', ['per_page' => 200])->throw()->json('domains', []));
        $zone = $this->bestZone($zones, $domain->hostname);
        $name = $this->relative($domain->hostname, $zone);
        [$existingZone, $existing] = array_pad(explode(':', (string) $domain->dns_record_id, 2), 2, '');
        $payload = ['type' => $type, 'name' => $name, 'data' => $address, 'ttl' => 300];
        $response = $existingZone === $zone && $existing !== ''
            ? $client->put("/domains/{$zone}/records/{$existing}", $payload)
            : $client->post("/domains/{$zone}/records", $payload);

        return $zone.':'.$this->id($response->throw(), 'domain_record.id');
    }

    /**
     * Create or update the record at Hetzner DNS.
     *
     * @param  string  $token
     * @param  WebsiteDomain  $domain
     * @param  string  $type
     * @param  string  $address
     * @return string
     */
    private function hetzner(string $token, WebsiteDomain $domain, string $type, string $address): string
    {
        $client = $this->hetznerClient($token);
        $zones = collect((array) $client->get('/zones', ['per_page' => 100])->throw()->json('zones', []));
        $zoneName = $this->bestZone($zones->map(fn (array $zone): string => strtolower((string) ($zone['name'] ?? '')))->all(), $domain->hostname);
        $zoneId = (string) ($zones->first(fn (array $zone): bool => strtolower((string) ($zone['name'] ?? '')) === $zoneName)['id'] ?? '');
        [$existingZone, $existing] = array_pad(explode(':', (string) $domain->dns_record_id, 2), 2, '');
        $payload = ['zone_id' => $zoneId, 'type' => $type, 'name' => $this->relative($domain->hostname, $zoneName), 'value' => $address, 'ttl' => 300];
        $response = $existingZone === $zoneId && $existing !== '' ? $client->put("/records/{$existing}", $payload) : $client->post('/records', $payload);

        return $zoneId.':'.$this->id($response->throw(), 'record.id');
    }

    /**
     * Create, update or delete the record at Route 53 (records there are identified by name and type).
     *
     * @param  string  $credential  access key ID and secret, as "id:secret"
     * @param  WebsiteDomain  $domain
     * @param  string  $type  A or AAAA
     * @param  string  $address
     * @param  string  $action  UPSERT or DELETE
     * @return string
     */
    private function route53(string $credential, WebsiteDomain $domain, string $type, string $address, string $action): string
    {
        [$key, $secret] = array_pad(explode(':', $credential, 2), 2, '');
        $zones = $this->route53Call($key, $secret, 'GET', 'https://route53.amazonaws.com/2013-04-01/hostedzone?maxitems=100');
        preg_match_all('#<HostedZone><Id>/hostedzone/([A-Z0-9]+)</Id><Name>([^<]+)</Name>#', $zones->body(), $matches, PREG_SET_ORDER);
        $names = [];
        foreach ($matches as $match) {
            $names[rtrim(strtolower($match[2]), '.')] = $match[1];
        }
        $zone = $names[$this->bestZone(array_keys($names), $domain->hostname)];
        $body = '<?xml version="1.0" encoding="UTF-8"?><ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/"><ChangeBatch><Changes><Change>'
            ."<Action>{$action}</Action><ResourceRecordSet><Name>".htmlspecialchars($domain->hostname, ENT_XML1).'.</Name>'
            ."<Type>{$type}</Type><TTL>300</TTL><ResourceRecords><ResourceRecord><Value>".htmlspecialchars($address, ENT_XML1).'</Value></ResourceRecord></ResourceRecords>'
            .'</ResourceRecordSet></Change></Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
        $this->route53Call($key, $secret, 'POST', "https://route53.amazonaws.com/2013-04-01/hostedzone/{$zone}/rrset", $body);

        return $zone.':'.$type;
    }

    /**
     * Send a signed Route 53 request and throw on failure.
     *
     * @param  string  $key
     * @param  string  $secret
     * @param  string  $method
     * @param  string  $url
     * @param  string  $body
     * @return Response
     */
    private function route53Call(string $key, string $secret, string $method, string $url, string $body = ''): Response
    {
        $headers = AwsSignature::headers($key, $secret, 'us-east-1', 'route53', $method, $url, $body === '' ? [] : ['Content-Type' => 'text/xml'], $body);
        unset($headers['Host']);
        $request = Http::withHeaders([...$headers, 'User-Agent' => 'BuildPusher'])->connectTimeout(5)->timeout(15);
        $response = $method === 'GET' ? $request->get($url) : $request->withBody($body, 'text/xml')->post($url);
        if ($response->failed()) {
            throw new RuntimeException('Route 53 answered HTTP '.$response->status().'.');
        }

        return $response;
    }

    /**
     * Choose the longest zone the hostname is in.
     *
     * @param  array<array-key, string>  $zones
     * @param  string  $hostname
     * @return string
     */
    private function bestZone(array $zones, string $hostname): string
    {
        $best = null;
        foreach ($zones as $zone) {
            if ($zone !== '' && ($hostname === $zone || str_ends_with($hostname, '.'.$zone)) && ($best === null || strlen($zone) > strlen($best))) {
                $best = $zone;
            }
        }
        if ($best === null) {
            throw new RuntimeException('No zone matching this hostname is available to the credential.');
        }

        return $best;
    }

    /**
     * Get the record name relative to its zone ("@" for the apex).
     *
     * @param  string  $hostname
     * @param  string  $zone
     * @return string
     */
    private function relative(string $hostname, string $zone): string
    {
        return $hostname === $zone ? '@' : substr($hostname, 0, -strlen('.'.$zone));
    }

    /**
     * Read a record ID from a response.
     *
     * @param  Response  $response
     * @param  string  $path
     * @return string
     */
    private function id(Response $response, string $path): string
    {
        $id = $response->json($path);
        if (! is_scalar($id) || (string) $id === '') {
            throw new RuntimeException('The DNS provider didn’t return a record ID.');
        }

        return (string) $id;
    }

    /**
     * Build a DigitalOcean API client.
     *
     * @param  string  $token
     * @return PendingRequest
     */
    private function digitalOceanClient(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.digitalocean.com/v2')->acceptJson()->asJson()->withToken($token)->connectTimeout(5)->timeout(15);
    }

    /**
     * Build a Hetzner DNS API client.
     *
     * @param  string  $token
     * @return PendingRequest
     */
    private function hetznerClient(string $token): PendingRequest
    {
        return Http::baseUrl('https://dns.hetzner.com/api/v1')->acceptJson()->asJson()->withHeaders(['Auth-API-Token' => $token])->connectTimeout(5)->timeout(15);
    }
}

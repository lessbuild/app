<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\WebsiteDomain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare's edge for a domain whose DNS BuildPusher manages: purging its cached pages, and the firewall rules that
 * block countries and addresses or rate-limit visitors. A zone keeps all its custom rules in one list, so only the
 * rules BuildPusher made for this hostname are replaced; everything else in the zone is left as it was.
 */
class CloudflareEdge
{
    /**
     * The phase that holds the zone's custom firewall rules.
     *
     * @var string
     */
    private const FIREWALL = 'http_request_firewall_custom';

    /**
     * The phase that holds the zone's rate-limiting rules.
     *
     * @var string
     */
    private const RATE_LIMIT = 'http_ratelimit';

    /**
     * Drop the domain's cached pages at Cloudflare.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     *
     * @throws RuntimeException
     */
    public function purge(WebsiteDomain $domain): void
    {
        [$token, $zone] = $this->zone($domain);
        $this->client($token)->post("/zones/{$zone}/purge_cache", ['hosts' => [$domain->hostname]])->throw();
    }

    /**
     * Put the domain's blocking and rate-limiting rules in place, or take them away when none are set.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     *
     * @throws RuntimeException
     */
    public function applySecurity(WebsiteDomain $domain): void
    {
        [$token, $zone] = $this->zone($domain);
        $host = '(http.host eq "'.addcslashes($domain->hostname, '"\\').'")';
        $blocks = [];
        if (($domain->blocked_countries ?? []) !== []) {
            $blocks[] = 'ip.geoip.country in {'.implode(' ', array_map(fn (string $code): string => '"'.$code.'"', $domain->blocked_countries ?? [])).'}';
        }
        if (($domain->blocked_ips ?? []) !== []) {
            $blocks[] = 'ip.src in {'.implode(' ', $domain->blocked_ips ?? []).'}';
        }
        $this->replace($token, $zone, self::FIREWALL, $domain, $blocks === [] ? null : [
            'action' => 'block', 'expression' => "{$host} and (".implode(' or ', $blocks).')', 'enabled' => true,
        ]);
        $this->replace($token, $zone, self::RATE_LIMIT, $domain, $domain->rate_limit_requests === null ? null : [
            'action' => 'block', 'expression' => $host, 'enabled' => true,
            'ratelimit' => ['characteristics' => ['cf.colo.id', 'ip.src'], 'period' => 10, 'requests_per_period' => $domain->rate_limit_requests, 'mitigation_timeout' => 10],
        ]);
    }

    /**
     * Replace this hostname's BuildPusher rule in one of the zone's phases (keeping every other rule), or remove it.
     *
     * @param  string  $token
     * @param  string  $zone
     * @param  string  $phase
     * @param  WebsiteDomain  $domain
     * @param  array<string, mixed>|null  $rule
     * @return void
     */
    private function replace(string $token, string $zone, string $phase, WebsiteDomain $domain, ?array $rule): void
    {
        $description = 'BuildPusher: '.$domain->hostname;
        $response = $this->client($token)->get("/zones/{$zone}/rulesets/phases/{$phase}/entrypoint");
        if ($response->status() === 404) {
            $existing = [];
        } else {
            $existing = (array) $response->throw()->json('result.rules', []);
        }
        $rules = [];
        foreach ($existing as $current) {
            if (is_array($current) && ($current['description'] ?? null) !== $description) {
                $rules[] = array_intersect_key($current, array_flip(['id', 'action', 'expression', 'description', 'enabled', 'action_parameters', 'ratelimit', 'logging']));
            }
        }
        if ($rule === null && count($rules) === count($existing)) {
            return;
        }
        if ($rule !== null) {
            $rules[] = [...$rule, 'description' => $description];
        }
        $this->client($token)->put("/zones/{$zone}/rulesets/phases/{$phase}/entrypoint", ['rules' => $rules])->throw();
    }

    /**
     * Get the domain's Cloudflare token and zone, from the DNS record BuildPusher manages for it.
     *
     * @param  WebsiteDomain  $domain
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException
     */
    private function zone(WebsiteDomain $domain): array
    {
        $domain->loadMissing('dnsProvider');
        $token = $domain->dnsProvider?->token;
        $zone = is_string($domain->dns_record_id) ? explode(':', $domain->dns_record_id, 2)[0] : '';
        if (! is_string($token) || $token === '' || $zone === '') {
            throw new RuntimeException('The domain’s DNS isn’t managed at Cloudflare yet.');
        }

        return [$token, $zone];
    }

    /**
     * Build an HTTP client for the Cloudflare API with the token, short timeouts and two quick retries.
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

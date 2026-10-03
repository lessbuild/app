<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\ProviderType;
use App\Jobs\Infrastructure\ApplyDomainEdge;
use App\Models\User;
use App\Models\WebsiteDomain;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveDomainEdgeSettings
{
    /**
     * Save a Cloudflare-managed domain's CDN and firewall settings and apply them (queued): serve it through
     * Cloudflare's CDN, block countries and addresses, and limit how many requests a visitor may make per 10 seconds.
     *
     * @param  User  $actor
     * @param  WebsiteDomain  $domain
     * @param  array<string, mixed>  $input
     * @return void
     *
     * @throws ValidationException
     */
    public function handle(User $actor, WebsiteDomain $domain, array $input): void
    {
        Gate::forUser($actor)->authorize('update', $domain->website);
        if ($domain->dns_provider_id === null || $domain->dns_record_id === null || $domain->dnsProvider?->type !== ProviderType::Cloudflare) {
            throw ValidationException::withMessages(['edge' => __('Manage this domain’s DNS with Cloudflare first.')]);
        }
        $data = Validator::make($input, [
            'cdn_proxied' => ['boolean'],
            'blocked_countries' => ['nullable', 'string', 'max:1000'],
            'blocked_ips' => ['nullable', 'string', 'max:5000'],
            'rate_limit_requests' => ['nullable', 'integer', 'between:1,10000'],
        ])->validate();
        $countries = array_values(array_unique(array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), preg_split('/[\s,]+/', (string) ($data['blocked_countries'] ?? '')) ?: []))));
        foreach ($countries as $code) {
            if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
                throw ValidationException::withMessages(['blocked_countries' => __(':code isn’t a two-letter country code.', ['code' => $code])]);
            }
        }
        $addresses = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['blocked_ips'] ?? '')) ?: []))));
        foreach ($addresses as $address) {
            [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > (str_contains((string) $ip, ':') ? 128 : 32)))) {
                throw ValidationException::withMessages(['blocked_ips' => __(':address isn’t an IP address or network.', ['address' => $address])]);
            }
        }
        $domain->forceFill([
            'cdn_proxied' => (bool) ($data['cdn_proxied'] ?? false),
            'blocked_countries' => $countries === [] ? null : $countries,
            'blocked_ips' => $addresses === [] ? null : $addresses,
            'rate_limit_requests' => isset($data['rate_limit_requests']) ? (int) $data['rate_limit_requests'] : null,
            'edge_error' => null,
        ])->save();
        ApplyDomainEdge::dispatch($domain->id)->afterCommit();
    }
}

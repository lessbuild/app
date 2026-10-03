<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\WebsiteDomain;

/** Sends a domain's DNS record changes to the provider that manages it: Cloudflare, or one of the others. */
final class DomainDns
{
    /**
     * Create a new DomainDns instance.
     *
     * @param  CloudflareDns  $cloudflare  Manages Cloudflare records.
     * @param  OtherDns  $others  Manages DigitalOcean, Hetzner DNS and Route 53 records.
     */
    public function __construct(private readonly CloudflareDns $cloudflare, private readonly OtherDns $others) {}

    /**
     * Create or update the domain's record.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function sync(WebsiteDomain $domain): void
    {
        $domain->loadMissing('dnsProvider');
        $domain->dnsProvider?->type === ProviderType::Cloudflare ? $this->cloudflare->sync($domain) : $this->others->sync($domain);
    }

    /**
     * Delete the domain's record, if we created one.
     *
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function delete(WebsiteDomain $domain): void
    {
        $domain->loadMissing('dnsProvider');
        $domain->dnsProvider?->type === ProviderType::Cloudflare ? $this->cloudflare->delete($domain) : $this->others->delete($domain);
    }
}

<?php

namespace App\Actions\Domain;

use App\Data\WebsiteDomainSaveResult;
use App\Jobs\ApplyWebsiteDomainsJob;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Services\CloudflareDns;
use Throwable;

class SaveWebsiteDomainAction
{
    /**
     * Persist a domain, synchronize optional DNS, and queue the website's proxy configuration.
     *
     * @param  array{hostname: string, type: string, redirect_url?: string|null, dns_provider_id?: int|string|null, is_temporary?: bool}  $attributes  Validated domain attributes.
     */
    public function __construct(private readonly CloudflareDns $cloudflare) {}

    public function handle(Website $website, User $actor, array $attributes): WebsiteDomainSaveResult
    {
        $domain = $website->domains()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'redirect_url' => $attributes['type'] === 'redirect' ? ($attributes['redirect_url'] ?? null) : null,
        ]);
        $warning = $this->synchronize($domain);
        ApplyWebsiteDomainsJob::dispatch($website->id);

        return new WebsiteDomainSaveResult($domain, $warning);
    }

    /**
     * Synchronize the domain's optional DNS record and retain the existing sanitized failure fallback.
     *
     * @return string|null A manual-DNS or failed-sync warning; null when synchronization succeeds.
     */
    private function synchronize(WebsiteDomain $domain): ?string
    {
        if (! $domain->dns_provider_id) {
            return __('Domain saved. Point its DNS record to the attached server, then run the certificate check.');
        }

        try {
            $this->cloudflare->sync($domain);

            return null;
        } catch (Throwable) {
            $domain->forceFill(['dns_status' => 'error', 'last_error' => 'DNS synchronization failed.', 'last_checked_at' => now()])->save();

            return __('Domain saved, but Cloudflare synchronization failed. Verify token permissions and zone access.');
        }
    }
}

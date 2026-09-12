<?php

namespace App\Actions\Domain;

use App\Exceptions\WebsiteDomainOperationException;
use App\Jobs\ApplyWebsiteDomainsJob;
use App\Models\WebsiteDomain;
use App\Services\CloudflareDns;
use Throwable;

class DeleteWebsiteDomainAction
{
    public function __construct(private readonly CloudflareDns $cloudflare) {}

    /**
     * Remove a non-primary domain only after DNS deletion succeeds, then queue proxy configuration.
     *
     * @return bool False when Cloudflare deletion fails and the domain must be retained.
     *
     * @throws WebsiteDomainOperationException If the primary domain is targeted.
     */
    public function handle(WebsiteDomain $domain): bool
    {
        if ($domain->type === 'primary') {
            throw new WebsiteDomainOperationException('The primary domain must be changed from website settings.');
        }

        try {
            $this->cloudflare->delete($domain);
        } catch (Throwable) {
            return false;
        }

        $websiteId = $domain->website_id;
        $domain->delete();
        ApplyWebsiteDomainsJob::dispatch($websiteId);

        return true;
    }
}

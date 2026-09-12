<?php

namespace App\Actions\Domain;

use App\Models\WebsiteDomain;
use App\Services\CloudflareDns;
use Throwable;

class SynchronizeWebsiteDomainAction
{
    public function __construct(private readonly CloudflareDns $cloudflare) {}

    /**
     * Synchronize an attached Cloudflare record and retain the existing sanitized failure status.
     *
     * @return string|null A synchronization warning, or null after success.
     */
    public function handle(WebsiteDomain $domain): ?string
    {
        try {
            $this->cloudflare->sync($domain);

            return null;
        } catch (Throwable) {
            $domain->forceFill(['dns_status' => 'error', 'last_error' => 'DNS synchronization failed.', 'last_checked_at' => now()])->save();

            return __('Cloudflare synchronization failed. Verify token permissions and zone access.');
        }
    }
}

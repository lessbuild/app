<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\WebsiteDomain;
use App\Services\Infrastructure\DomainDns;
use Carbon\CarbonImmutable;
use Throwable;

final class SyncWebsiteDomain
{
    /**
     * Create a new SyncWebsiteDomain instance.
     *
     * Points a website domain's DNS at its server.
     *
     * @param  DomainDns  $dns  Creates or updates the record at the domain's DNS provider.
     */
    public function __construct(private readonly DomainDns $dns) {}

    /**
     * Point the domain's Cloudflare record at the website's server. Returns a warning if Cloudflare refused, or null.
     *
     * @param  WebsiteDomain  $domain
     * @return string|null
     */
    public function handle(WebsiteDomain $domain): ?string
    {
        try {
            $this->dns->sync($domain);

            return null;
        } catch (Throwable $exception) {
            report($exception);
            $domain->forceFill(['dns_status' => 'error', 'last_error' => 'DNS synchronisation failed.', 'last_checked_at' => CarbonImmutable::now('UTC')])->save();

            return (string) __('The DNS provider couldn’t be updated. Check the credential’s permissions and that the domain’s zone is there.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\WebsiteDomain;
use App\Services\Infrastructure\CloudflareDns;
use Carbon\CarbonImmutable;
use Throwable;

final class SyncWebsiteDomain
{
    /**
     * Points a website domain's DNS at its server.
     *
     * @param  CloudflareDns  $cloudflare  Creates or updates the record at Cloudflare.
     */
    public function __construct(private readonly CloudflareDns $cloudflare) {}

    /**
     * Point the domain's Cloudflare record at the website's server. Returns a warning if Cloudflare refused, or null.
     *
     * @param  WebsiteDomain  $domain
     * @return string|null
     */
    public function handle(WebsiteDomain $domain): ?string
    {
        try {
            $this->cloudflare->sync($domain);

            return null;
        } catch (Throwable $exception) {
            report($exception);
            $domain->forceFill(['dns_status' => 'error', 'last_error' => 'DNS synchronisation failed.', 'last_checked_at' => CarbonImmutable::now('UTC')])->save();

            return (string) __('Cloudflare couldn’t be updated. Check the token’s permissions and zone access.');
        }
    }
}

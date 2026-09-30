<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\WebsiteDomain;
use App\Services\Infrastructure\CloudflareDns;
use App\Services\Infrastructure\CloudflareEdge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ApplyDomainEdge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Cloudflare can be briefly unavailable, so a change gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 20;

    /**
     * Create a new ApplyDomainEdge instance.
     *
     * Puts a domain's CDN and firewall settings in place at Cloudflare.
     *
     * @param  int  $domainId  The domain.
     */
    public function __construct(public readonly int $domainId) {}

    /**
     * Update the DNS record (proxied or not) and the firewall rules, clearing the last error when it works.
     *
     * @param  CloudflareDns  $dns
     * @param  CloudflareEdge  $edge
     * @return void
     */
    public function handle(CloudflareDns $dns, CloudflareEdge $edge): void
    {
        $domain = WebsiteDomain::query()->find($this->domainId);
        if ($domain === null) {
            return;
        }
        $dns->sync($domain);
        $edge->applySecurity($domain);
        $domain->forceFill(['edge_error' => null])->save();
    }

    /**
     * Record that Cloudflare refused the change.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
        WebsiteDomain::query()->whereKey($this->domainId)->update(['edge_error' => 'Cloudflare refused the change. Check that the token can edit the zone’s DNS, cache and firewall rules.']);
    }
}

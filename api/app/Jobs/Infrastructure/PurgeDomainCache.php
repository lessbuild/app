<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\WebsiteDomain;
use App\Services\Infrastructure\CloudflareEdge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class PurgeDomainCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two tries: a missed purge only means cached pages expire on their own.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Create a new PurgeDomainCache instance.
     *
     * Purges a CDN-served domain's cached pages after a deploy.
     *
     * @param  int  $domainId  The domain.
     */
    public function __construct(public readonly int $domainId) {}

    /**
     * Purge the domain's cache at Cloudflare.
     *
     * @param  CloudflareEdge  $edge
     * @return void
     */
    public function handle(CloudflareEdge $edge): void
    {
        $domain = WebsiteDomain::query()->find($this->domainId);
        if ($domain !== null && $domain->cdn_proxied) {
            $edge->purge($domain);
        }
    }

    /**
     * Report a purge that kept failing.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}

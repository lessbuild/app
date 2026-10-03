<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Deploy\DeployFinished;
use App\Jobs\Infrastructure\PurgeDomainCache;
use App\Models\Build;
use App\Models\WebsiteDomain;
use Illuminate\Events\Dispatcher;

final class CdnPurgeSubscriber
{
    /**
     * Register for finished deploys.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [DeployFinished::class => 'finished'];
    }

    /**
     * After a successful deploy, purge Cloudflare's cache for the website's domains served through its CDN, so
     * visitors get the new version straight away.
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function finished(DeployFinished $event): void
    {
        if ($event->build->status !== Build::STATUS_SUCCEEDED) {
            return;
        }
        WebsiteDomain::query()->where('website_id', $event->build->website_id)->where('cdn_proxied', true)->pluck('id')
            ->each(fn (int $id) => PurgeDomainCache::dispatch($id));
    }
}

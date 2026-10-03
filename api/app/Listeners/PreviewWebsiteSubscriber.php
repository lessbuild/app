<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Infrastructure\WebsiteProvisioned;
use App\Events\Infrastructure\WebsiteProvisioningFailed;
use App\Services\Deploy\Previews;
use Illuminate\Events\Dispatcher;

/** Moves a preview on when Infrastructure finishes (or fails) setting up its website. */
final class PreviewWebsiteSubscriber
{
    /**
     * Create a new PreviewWebsiteSubscriber instance.
     *
     * Passes website setup outcomes to the preview lifecycle.
     *
     * @param  Previews  $previews  Deploys a preview once its website is ready.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Register the website setup outcomes previews wait on.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            WebsiteProvisioned::class => 'provisioned',
            WebsiteProvisioningFailed::class => 'failed',
        ];
    }

    /**
     * Deploy the preview of a website that has just been set up.
     *
     * @param  WebsiteProvisioned  $event
     * @return void
     */
    public function provisioned(WebsiteProvisioned $event): void
    {
        $this->previews->websiteReady($event->website);
    }

    /**
     * Mark the preview of a website that couldn't be set up as failed.
     *
     * @param  WebsiteProvisioningFailed  $event
     * @return void
     */
    public function failed(WebsiteProvisioningFailed $event): void
    {
        $this->previews->websiteFailed($event->website);
    }
}

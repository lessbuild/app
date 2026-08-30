<?php

namespace App\Observers;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;

class WebsiteObserver
{
    /**
     * When a website is deleted
     */
    public function deleted(Website $website): void
    {
        if ($website->isForceDeleting()) {
            return;
        }

        DeleteWebsiteFromCaddyJob::dispatch($website->id);
    }
}

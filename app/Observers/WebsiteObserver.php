<?php

namespace App\Observers;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;

class WebsiteObserver
{
    /**
     * When a website is deleted
     *
     * @param  \App\Models\Website  $website
     * @return void
     */
    public function deleting(Website $website)
    {
        DeleteWebsiteFromCaddyJob::dispatch($website->toArray());

        $website->repositories()->delete();
    }
}

<?php

namespace App\Actions\Web;

use App\Jobs\Web\RefreshWebsiteLogJob;
use App\Models\Website;
use App\Models\WebsiteLogSnapshot;

class QueueWebsiteLogRefreshAction
{
    /**
     * Queue a runtime-log refresh only after the website is active.
     *
     * @param  Website  $website  Website whose runtime log snapshot is requested.
     * @param  string  $type  Route-constrained runtime-log type.
     * @return bool Whether a snapshot was queued.
     */
    public function handle(Website $website, string $type): bool
    {
        if ($website->provisioning_status !== Website::STATUS_ACTIVE) {
            return false;
        }

        $website->runtimeLogs()->updateOrCreate(['type' => $type], [
            'status' => WebsiteLogSnapshot::STATUS_QUEUED,
            'error' => null,
        ]);
        RefreshWebsiteLogJob::dispatch($website->id, $type);

        return true;
    }
}

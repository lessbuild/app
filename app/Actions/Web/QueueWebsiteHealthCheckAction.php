<?php

namespace App\Actions\Web;

use App\Data\WebsiteHealthCheckResult;
use App\Jobs\Web\CheckWebsiteHealthJob;
use App\Models\Server;
use App\Models\Website;

class QueueWebsiteHealthCheckAction
{
    /**
     * Check manual-health eligibility and queue one deduplicated health check.
     *
     * @param  Website  $website  Website whose health state and server are checked.
     * @return WebsiteHealthCheckResult The eligibility or queued outcome.
     */
    public function handle(Website $website): WebsiteHealthCheckResult
    {
        $website->loadMissing('server');

        if (! $website->health_check_enabled) {
            return new WebsiteHealthCheckResult(WebsiteHealthCheckResult::DISABLED);
        }

        if ($website->provisioning_status !== Website::STATUS_ACTIVE
            || $website->server?->provisioning_status !== Server::STATUS_ACTIVE) {
            return new WebsiteHealthCheckResult(WebsiteHealthCheckResult::INACTIVE);
        }

        CheckWebsiteHealthJob::dispatch($website->id);

        return new WebsiteHealthCheckResult(WebsiteHealthCheckResult::QUEUED);
    }
}

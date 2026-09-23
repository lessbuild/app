<?php

namespace App\Modules\Deployer\Actions\Web;

use App\Modules\Deployer\Jobs\Web\CleanupWebsitePlacementJob;
use App\Modules\Deployer\Models\Website;

class QueueWebsitePlacementCleanupAction
{
    /**
     * Reset the prior cleanup error and queue cleanup for the still-recorded previous placement.
     *
     * @param  Website  $website  Website carrying the previous placement identity.
     * @return bool Whether a previous placement existed and cleanup was queued.
     */
    public function handle(Website $website): bool
    {
        if (! $website->previous_server_id) {
            return false;
        }

        $previousServerId = (int) $website->previous_server_id;
        $website->update(['placement_cleanup_error' => null]);
        CleanupWebsitePlacementJob::dispatch(
            $website->id,
            $previousServerId,
            $website->deployment_slug,
        );

        return true;
    }
}

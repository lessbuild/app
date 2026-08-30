<?php

namespace App\Observers;

use App\Actions\Droplet\DeleteDropletAction;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;

class ServerObserver
{
    /**
     * Server Deleting
     *
     * @return void
     *
     * @throws \Exception
     */
    public function deleting(Server $server)
    {
        (new DeleteDropletAction)->handle($server);

        Website::withTrashed()
            ->where('previous_server_id', $server->id)
            ->update([
                'previous_server_id' => null,
                'placement_cleanup_error' => null,
            ]);

        Website::withTrashed()->where('server_id', $server->id)->each(function (Website $website) use ($server): void {
            if ($website->previous_server_id && $website->previous_server_id !== $server->id) {
                CleanupWebsitePlacementJob::dispatch(
                    $website->id,
                    $website->previous_server_id,
                    $website->deployment_slug,
                );
            }
            Repository::withTrashed()
                ->where('website_id', $website->id)
                ->each(function (Repository $repository): void {
                    $repository->builds()->each(function (Build $build): void {
                        $build->logs()->delete();
                        $build->delete();
                    });
                    $repository->forceDelete();
                });

            // The whole droplet is gone, so no remote Caddy cleanup job is
            // necessary for each child website.
            $website->forceDeleteQuietly();
        });
    }
}

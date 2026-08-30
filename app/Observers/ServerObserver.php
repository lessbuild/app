<?php

namespace App\Observers;

use App\Actions\Droplet\DeleteDropletAction;
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

        Website::withTrashed()->where('server_id', $server->id)->each(function (Website $website): void {
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

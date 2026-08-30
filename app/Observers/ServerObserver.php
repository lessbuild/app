<?php

namespace App\Observers;

use App\Actions\Droplet\DeleteDropletAction;
use App\Models\Repository;
use App\Models\Server;

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

        $server->websites()->each(function ($website): void {
            Repository::withTrashed()
                ->where('website_id', $website->id)
                ->each(function (Repository $repository): void {
                    $repository->builds()->delete();
                    $repository->forceDelete();
                });

            // The whole droplet is gone, so no remote Caddy cleanup job is
            // necessary for each child website.
            $website->deleteQuietly();
        });
    }
}

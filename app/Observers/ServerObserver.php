<?php

namespace App\Observers;

use App\Actions\Droplet\DeleteDropletAction;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Server;

class ServerObserver
{
    /**
     * When a server is created
     *
     * @param  \App\Models\Server  $server
     * @return void
     */
    public function created(Server $server)
    {
        InitialiseServerJob::dispatch($server);
    }

    /**
     * Run the provision jobs now we have an IP
     *
     * @param  \App\Models\Server  $server
     * @return void
     */
    public function updated(Server $server)
    {
    }

    /**
     * Server Deleting
     *
     * @param  \App\Models\Server  $server
     * @return void
     *
     * @throws \Exception
     */
    public function deleting(Server $server)
    {
        (new DeleteDropletAction())->handle($server);

        $server->websites()->delete();
    }
}

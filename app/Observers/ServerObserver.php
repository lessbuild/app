<?php

namespace App\Observers;

use App\Actions\Droplet\DeleteDropletAction;
use App\Models\Server;

class ServerObserver
{
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

<?php

namespace App\Actions\Droplet;

use App\Models\Server;
use App\Services\DigitalOcean;

class DeleteDropletAction
{
    /**
     * Delete a droplet
     *
     * @param  \App\Models\Server  $server
     * @return void
     *
     * @throws \Exception
     */
    public function handle(Server $server)
    {
        $digitalOcean = new DigitalOcean($server->provider->token);

        if(isset($server->ssh_fingerprint)) {
            $digitalOcean->deleteSSHKey($server->ssh_fingerprint);
        }

        if(isset($server->identifier)) {
            $digitalOcean->destroyDroplet($server->identifier);
        }
    }
}

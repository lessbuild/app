<?php

namespace App\Actions\Droplet;

use App\Models\Server;
use App\Services\DigitalOcean;
use RuntimeException;

class DeleteDropletAction
{
    /**
     * Delete a droplet
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle(Server $server)
    {
        if (! $server->ssh_fingerprint && ! $server->identifier) {
            return;
        }

        if (! $server->provider) {
            throw new RuntimeException('The server cloud provider is no longer available.');
        }

        $digitalOcean = new DigitalOcean($server->provider->token);

        if ($server->ssh_fingerprint && ! $digitalOcean->deleteSSHKey($server->ssh_fingerprint)) {
            throw new RuntimeException('DigitalOcean could not delete the server SSH key.');
        }

        if ($server->identifier && ! $digitalOcean->destroyDroplet($server->identifier)) {
            throw new RuntimeException('DigitalOcean could not delete the server droplet.');
        }
    }
}

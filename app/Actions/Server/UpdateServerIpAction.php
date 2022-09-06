<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\DigitalOcean;

class UpdateServerIpAction
{
    /**
     * @var \App\Models\Server
     */
    private Server $server;

    /**
     * @var \App\Services\DigitalOcean
     */
    private DigitalOcean $serverProvider;

    /**
     * @param  \App\Models\Server  $server
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->serverProvider = new DigitalOcean($server->provider->token);
    }

    /**
     * @return bool
     *
     * @throws \Exception
     */
    public function handle()
    {
        // Takes a while for the network to be set
        while (empty($this->droplet['networks']['v4'])) {
            sleep(1);

            $this->droplet = $this->serverProvider->getDroplet($this->server->identifier);
        }

        $this->server->update([
            'public_ip' => $this->droplet['networks']['v4'][0]['ip_address'],
            'private_ip' => $this->droplet['networks']['v4'][1]['ip_address'],
        ]);

        return true;
    }
}

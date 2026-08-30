<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\DigitalOcean;
use RuntimeException;

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
        if (! $this->server->identifier) {
            throw new RuntimeException('The cloud provider has not assigned a server identifier yet.');
        }

        $droplet = $this->serverProvider->getDroplet($this->server->identifier);
        $networks = collect($droplet['networks']['v4'] ?? []);
        $publicIp = $networks->firstWhere('type', 'public')['ip_address'] ?? null;
        $privateIp = $networks->firstWhere('type', 'private')['ip_address'] ?? null;

        if (! $publicIp) {
            throw new RuntimeException('The server public network is not ready yet.');
        }

        $this->server->update([
            'public_ip' => $publicIp,
            'private_ip' => $privateIp,
        ]);

        return true;
    }
}

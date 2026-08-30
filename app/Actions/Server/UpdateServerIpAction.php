<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ServerProviderResolver;
use RuntimeException;

class UpdateServerIpAction
{
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * @throws \Exception
     */
    public function handle(Server $server): bool
    {
        if (! $server->identifier) {
            throw new RuntimeException('The cloud provider has not assigned a server identifier yet.');
        }

        if (! $server->provider) {
            throw new RuntimeException('The server cloud provider is no longer available.');
        }

        $cloudServer = $this->providers->resolve($server->provider)->server($server->identifier);

        if (! $cloudServer->publicIp) {
            throw new RuntimeException('The server public network is not ready yet.');
        }

        $server->update([
            'public_ip' => $cloudServer->publicIp,
            'private_ip' => $cloudServer->privateIp,
        ]);

        return true;
    }
}

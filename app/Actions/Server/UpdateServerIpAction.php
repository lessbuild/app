<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ServerProviderResolver;
use App\Services\SshHostIdentity;
use RuntimeException;

class UpdateServerIpAction
{
    public function __construct(
        private readonly ServerProviderResolver $providers,
        private readonly SshHostIdentity $hostIdentity,
    ) {}

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

        $identity = $this->hostIdentity->scan($cloudServer->publicIp, $server->ssh_port ?: 22);

        $server->update([
            'public_ip' => $cloudServer->publicIp,
            'private_ip' => $cloudServer->privateIp,
            'ssh_host_key' => $identity['known_host'],
            'ssh_host_fingerprint' => $identity['fingerprint'],
        ]);

        return true;
    }
}

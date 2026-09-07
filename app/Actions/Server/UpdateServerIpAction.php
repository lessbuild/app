<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ServerProviderResolver;
use App\Services\SshHostIdentity;
use RuntimeException;

class UpdateServerIpAction
{
    /**
     * Resolve cloud addresses and capture the corresponding SSH host identity.
     *
     * @param  ServerProviderResolver  $providers  Resolver for authenticated server-provider clients.
     * @param  SshHostIdentity  $hostIdentity  Scanner that captures the SSH host key used for subsequent pinned connections.
     */
    public function __construct(
        private readonly ServerProviderResolver $providers,
        private readonly SshHostIdentity $hostIdentity,
    ) {}

    /**
     * Read the cloud server addresses, scan its SSH host identity, and persist the public/private addresses and pinned host key.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @return bool True after the cloud addresses and SSH host identity have been saved.
     *
     * @throws \Exception
     * @throws RuntimeException If the server, provider, or public address is unavailable.
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

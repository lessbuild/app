<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ServerProviderResolver;
use RuntimeException;

class DeleteCloudServerAction
{
    /**
     * Resolve the provider that owns each cloud server and its provisioning SSH key.
     *
     * @param  ServerProviderResolver  $providers  Resolver for authenticated server-provider clients.
     */
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Delete the owned cloud server before its provisioning SSH key; return without remote work when there is no owned cloud resource.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     *
     * @throws RuntimeException If the provider is unavailable or refuses server or SSH-key deletion.
     */
    public function handle(Server $server): void
    {
        $hasOwnedSshKey = $server->ssh_fingerprint && $server->ssh_key_owned;
        if (! $hasOwnedSshKey && ! $server->identifier) {
            return;
        }

        if (! $server->provider) {
            throw new RuntimeException('The server cloud provider is no longer available.');
        }

        $provider = $this->providers->resolve($server->provider);

        if ($server->identifier && ! $provider->deleteServer($server->identifier)) {
            throw new RuntimeException("{$provider->name()} could not delete the cloud server.");
        }

        // Keep the login key usable until the instance is confirmed absent. If
        // key cleanup fails after that, a retry is safe because provider delete
        // operations treat an already-missing server as success.
        if ($hasOwnedSshKey && ! $provider->deleteSshKey($server->ssh_fingerprint)) {
            throw new RuntimeException("{$provider->name()} could not delete the server SSH key.");
        }
    }
}

<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ServerProviderResolver;
use RuntimeException;

class DeleteCloudServerAction
{
    public function __construct(private readonly ServerProviderResolver $providers) {}

    public function handle(Server $server): void
    {
        if (! $server->ssh_fingerprint && ! $server->identifier) {
            return;
        }

        if (! $server->provider) {
            throw new RuntimeException('The server cloud provider is no longer available.');
        }

        $provider = $this->providers->resolve($server->provider);

        if ($server->ssh_fingerprint && ! $provider->deleteSshKey($server->ssh_fingerprint)) {
            throw new RuntimeException("{$provider->name()} could not delete the server SSH key.");
        }

        if ($server->identifier && ! $provider->deleteServer($server->identifier)) {
            throw new RuntimeException("{$provider->name()} could not delete the cloud server.");
        }
    }
}

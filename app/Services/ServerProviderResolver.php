<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Models\Provider;
use RuntimeException;

class ServerProviderResolver
{
    /**
     * Construct the cloud adapter for a stored provider connection.
     *
     * @param  Provider  $provider  The connection supplying the provider type and API token.
     * @return ServerProvider The supported provider adapter using those credentials.
     */
    public function resolve(Provider $provider): ServerProvider
    {
        return $this->resolveCredentials($provider->provider, $provider->token);
    }

    /**
     * Select a cloud adapter from an explicit provider type and API token.
     *
     * @param  string  $type  The DigitalOcean, Hetzner or Vultr provider key.
     * @param  string  $token  The API token to pass to the selected adapter.
     * @return ServerProvider The constructed cloud-provider adapter.
     *
     * @throws RuntimeException If the provider type does not support server provisioning.
     */
    public function resolveCredentials(string $type, string $token): ServerProvider
    {
        return match ($type) {
            Provider::TYPE_DIGITALOCEAN => new DigitalOcean($token),
            Provider::TYPE_HETZNER => new HetznerCloud($token),
            Provider::TYPE_VULTR => new Vultr($token),
            default => throw new RuntimeException("Unsupported server provider: {$type}."),
        };
    }
}

<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Models\Provider;
use RuntimeException;

class ServerProviderResolver
{
    public function resolve(Provider $provider): ServerProvider
    {
        return $this->resolveCredentials($provider->provider, $provider->token);
    }

    public function resolveCredentials(string $type, string $token): ServerProvider
    {
        return match ($type) {
            Provider::TYPE_DIGITALOCEAN => new DigitalOcean($token),
            default => throw new RuntimeException("Unsupported server provider: {$type}."),
        };
    }
}

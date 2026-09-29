<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Enums\ProviderType;
use App\Models\Provider;
use RuntimeException;

/** The API client for a provider that hosts servers. Bound in the container so tests can swap in a fake. */
class ServerProviderResolver
{
    /**
     * Make the API client for a provider's stored credential.
     *
     * @param  Provider  $provider
     * @return ServerProvider
     */
    public function resolve(Provider $provider): ServerProvider
    {
        return $this->resolveCredentials($provider->type, $provider->token);
    }

    /**
     * Make the API client for a provider type and token; types that don't host servers throw.
     *
     * @param  ProviderType  $type
     * @param  string  $token
     * @return ServerProvider
     */
    public function resolveCredentials(ProviderType $type, string $token): ServerProvider
    {
        return match ($type) {
            ProviderType::DigitalOcean => new DigitalOcean($token),
            ProviderType::Hetzner => new HetznerCloud($token),
            ProviderType::Vultr => new Vultr($token),
            ProviderType::Linode => new Linode($token),
            default => throw new RuntimeException("{$type->label()} doesn’t host servers."),
        };
    }
}

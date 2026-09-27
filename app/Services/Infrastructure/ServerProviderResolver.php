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
    public function resolve(Provider $provider): ServerProvider
    {
        return $this->resolveCredentials($provider->type, $provider->token);
    }

    public function resolveCredentials(ProviderType $type, string $token): ServerProvider
    {
        return match ($type) {
            ProviderType::DigitalOcean => new DigitalOcean($token),
            ProviderType::Hetzner => new HetznerCloud($token),
            ProviderType::Vultr => new Vultr($token),
            default => throw new RuntimeException("{$type->label()} doesn’t host servers."),
        };
    }
}

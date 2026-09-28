<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Support\Collection;
use Throwable;

/** Looks up what each cloud server costs a month from its provider's size catalog (USD for DigitalOcean and Vultr, EUR gross for Hetzner). */
class ServerPricing
{
    /**
     * Create a new ServerPricing instance.
     *
     * Prices servers.
     *
     * @param  ServerProviderResolver  $providers  Talks to each server's provider.
     */
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Refresh the monthly cost of every cloud server, one catalog request per provider. A provider whose catalog can't be
     * read keeps its servers' last known costs.
     *
     * @param  Collection<int, Server>  $servers
     * @return int servers priced
     */
    public function refresh(Collection $servers): int
    {
        $priced = 0;
        foreach ($servers->whereNotNull('provider_id')->whereNotNull('size')->where('monthly_cost_source', '!==', 'manual')->groupBy('provider_id') as $group) {
            $provider = $group->first()?->provider;
            if (! $provider instanceof Provider || ! $provider->type->hostsServers()) {
                continue;
            }
            try {
                $sizes = $this->providers->resolve($provider)->sizes();
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }
            foreach ($group as $server) {
                $price = $this->price($provider->type, $sizes, (string) $server->size, $server->region);
                if ($price !== null) {
                    $server->forceFill(['monthly_cost' => $price, 'monthly_cost_currency' => $provider->type === ProviderType::Hetzner ? 'EUR' : 'USD', 'monthly_cost_source' => 'provider', 'monthly_cost_checked_at' => now()])->save();
                    $priced++;
                }
            }
        }

        return $priced;
    }

    /**
     * Look up the monthly price of a size in the provider's size catalog: Hetzner's gross price for the server's
     * location (or the first listed), Vultr's and DigitalOcean's monthly cost. Null when the size isn't listed.
     *
     * @param  ProviderType  $type
     * @param  list<array<string, mixed>>  $sizes
     * @param  string  $size
     * @param  string|null  $region
     * @return float|null
     */
    public function price(ProviderType $type, array $sizes, string $size, ?string $region): ?float
    {
        foreach ($sizes as $entry) {
            $matches = match ($type) {
                ProviderType::Hetzner => ($entry['name'] ?? null) === $size,
                ProviderType::Vultr => ($entry['id'] ?? null) === $size,
                default => ($entry['slug'] ?? null) === $size,
            };
            if (! $matches) {
                continue;
            }
            if ($type === ProviderType::Hetzner) {
                $prices = collect(is_array($entry['prices'] ?? null) ? $entry['prices'] : []);
                $price = $prices->firstWhere('location', $region) ?? $prices->first();
                $gross = is_array($price) && is_array($price['price_monthly'] ?? null) ? ($price['price_monthly']['gross'] ?? null) : null;

                return is_numeric($gross) ? round((float) $gross, 2) : null;
            }
            $value = $entry[$type === ProviderType::Vultr ? 'monthly_cost' : 'price_monthly'] ?? null;

            return is_numeric($value) ? round((float) $value, 2) : null;
        }

        return null;
    }
}

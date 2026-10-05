<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Support\Collection;

class ServerCatalog
{
    /**
     * Create a new ServerCatalog instance.
     *
     * Reads a provider's regions, sizes and images for the server form.
     *
     * @param  ServerPricing  $pricing  Reads a size's monthly price from the provider's catalog.
     */
    public function __construct(private readonly ServerPricing $pricing) {}

    /**
     * Get the provider's regions, sizes and images for the server form. Each size carries its monthly price (null when
     * the provider doesn't list one) and the currency the provider bills in.
     *
     * @param  Provider  $provider
     * @param  ServerProvider  $client
     * @return array{regions: array<int, array{id: string, label: string}>, sizes: array<int, array{id: string, label: string, price: float|null, currency: string}>, images: array<int, array{id: string, label: string}>}
     */
    public function for(Provider $provider, ServerProvider $client): array
    {
        $sizes = $client->sizes();
        $catalog = match ($provider->type) {
            ProviderType::Hetzner => $this->hetzner($client, $sizes),
            ProviderType::Vultr => $this->vultr($client, $sizes),
            ProviderType::Linode => $this->linode($client, $sizes),
            ProviderType::Lightsail => $this->lightsail($client, $sizes),
            // Their adapters return DigitalOcean-shaped catalogues, priced in euros.
            ProviderType::Scaleway, ProviderType::UpCloud, ProviderType::Ovh => $this->digitalOcean($client, $sizes, '€'),
            default => $this->digitalOcean($client, $sizes),
        };
        $currency = ServerPricing::currency($provider->type);
        $catalog['sizes'] = array_map(fn (array $size): array => [...$size, 'price' => $this->pricing->price($provider->type, $sizes, $size['id'], null), 'currency' => $currency], $catalog['sizes']);

        return $catalog;
    }

    /**
     * Normalize DigitalOcean regions, sizes and Ubuntu images for server-selection controls.
     *
     * @param  ServerProvider  $client  The DigitalOcean adapter supplying catalog responses.
     * @param  list<array<string, mixed>>  $sizes  The provider's size catalog, read once.
     * @param  list<array<string, mixed>>  $sizes  The provider\'s size catalog, read once.
     * @param  string  $symbol  The currency symbol prices are shown with.
     * @return array{regions: list<array{id: string, label: string}>, sizes: list<array{id: string, label: string}>, images: list<array{id: string, label: string}>} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function digitalOcean(ServerProvider $client, array $sizes, string $symbol = '$'): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())
                ->filter(fn (array $region): bool => (bool) ($region['available'] ?? true))
                ->map(fn (array $region): array => [
                    'id' => (string) ($region['slug'] ?? ''),
                    'label' => (string) ($region['name'] ?? $region['slug'] ?? ''),
                ])),
            'sizes' => $this->sort(collect($sizes)->map(fn (array $size): array => [
                'id' => (string) ($size['slug'] ?? ''),
                'label' => sprintf(
                    '%s · %s GB RAM · %s vCPU%s',
                    (string) ($size['description'] ?? $size['slug'] ?? ''),
                    $this->number(((float) ($size['memory'] ?? 0)) / 1024),
                    (string) ($size['vcpus'] ?? '?'),
                    // A size without a listed price (OVHcloud's catalogue can miss one) shows no price rather than 0.
                    is_numeric($size['price_monthly'] ?? null) ? ' · '.$symbol.$this->number((float) $size['price_monthly']).'/month' : '',
                ),
            ])),
            'images' => $this->sort(collect($client->images())
                ->filter(fn (array $image): bool => strcasecmp((string) ($image['distribution'] ?? ''), 'Ubuntu') === 0)
                ->map(fn (array $image): array => [
                    'id' => (string) ($image['slug'] ?? $image['id'] ?? ''),
                    'label' => trim((string) ($image['distribution'] ?? '').' '.(string) ($image['name'] ?? '')),
                ])),
        ];
    }

    /**
     * Normalize Hetzner regions, sizes and Ubuntu images for server-selection controls.
     *
     * @param  ServerProvider  $client  The Hetzner adapter supplying catalog responses.
     * @param  list<array<string, mixed>>  $sizes  The provider's size catalog, read once.
     * @param  list<array<string, mixed>>  $sizes  The provider\'s size catalog, read once.
     * @return array{regions: list<array{id: string, label: string}>, sizes: list<array{id: string, label: string}>, images: list<array{id: string, label: string}>} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function hetzner(ServerProvider $client, array $sizes): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $region): array => [
                'id' => (string) ($region['name'] ?? ''),
                'label' => trim((string) ($region['city'] ?? $region['name'] ?? '').', '.(string) ($region['country'] ?? '')),
            ])),
            'sizes' => $this->sort(collect($sizes)->map(fn (array $size): array => [
                'id' => (string) ($size['name'] ?? ''),
                'label' => sprintf(
                    '%s · %s GB RAM · %s vCPU · %s GB disk',
                    (string) ($size['name'] ?? ''),
                    $this->number((float) ($size['memory'] ?? 0)),
                    (string) ($size['cores'] ?? '?'),
                    (string) ($size['disk'] ?? '?'),
                ),
            ])),
            'images' => $this->sort(collect($client->images())
                ->filter(fn (array $image): bool => strcasecmp((string) ($image['os_flavor'] ?? ''), 'ubuntu') === 0)
                ->map(fn (array $image): array => [
                    'id' => (string) ($image['name'] ?? $image['id'] ?? ''),
                    'label' => (string) ($image['description'] ?? $image['name'] ?? ''),
                ])),
        ];
    }

    /**
     * Normalize Vultr regions, sizes and Ubuntu images for server-selection controls.
     *
     * @param  ServerProvider  $client  The Vultr adapter supplying catalog responses.
     * @param  list<array<string, mixed>>  $sizes  The provider's size catalog, read once.
     * @param  list<array<string, mixed>>  $sizes  The provider\'s size catalog, read once.
     * @return array{regions: list<array{id: string, label: string}>, sizes: list<array{id: string, label: string}>, images: list<array{id: string, label: string}>} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function vultr(ServerProvider $client, array $sizes): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $region): array => [
                'id' => (string) ($region['id'] ?? ''),
                'label' => trim((string) ($region['city'] ?? $region['id'] ?? '').', '.(string) ($region['country'] ?? '')),
            ])),
            'sizes' => $this->sort(collect($sizes)->map(fn (array $size): array => [
                'id' => (string) ($size['id'] ?? ''),
                'label' => sprintf(
                    '%s · %s GB RAM · %s vCPU · $%s/month',
                    (string) ($size['id'] ?? ''),
                    $this->number(((float) ($size['ram'] ?? 0)) / 1024),
                    (string) ($size['vcpu_count'] ?? '?'),
                    $this->number((float) ($size['monthly_cost'] ?? 0)),
                ),
            ])),
            'images' => $this->sort(collect($client->images())
                ->filter(fn (array $image): bool => str_contains(strtolower((string) ($image['name'] ?? '')), 'ubuntu'))
                ->map(fn (array $image): array => [
                    'id' => (string) ($image['id'] ?? ''),
                    'label' => (string) ($image['name'] ?? ''),
                ])),
        ];
    }

    /**
     * Normalize Linode regions, instance types and Ubuntu images for server-selection controls.
     *
     * @param  ServerProvider  $client  The Linode adapter supplying catalog responses.
     * @param  list<array<string, mixed>>  $sizes  The provider's size catalog, read once.
     * @param  list<array<string, mixed>>  $sizes  The provider\'s size catalog, read once.
     * @return array{regions: list<array{id: string, label: string}>, sizes: list<array{id: string, label: string}>, images: list<array{id: string, label: string}>}
     */
    private function linode(ServerProvider $client, array $sizes): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $region): array => [
                'id' => (string) ($region['id'] ?? ''),
                'label' => trim((string) ($region['label'] ?? $region['id'] ?? '').' ('.strtoupper((string) ($region['country'] ?? '')).')'),
            ])),
            'sizes' => $this->sort(collect($sizes)->map(fn (array $size): array => [
                'id' => (string) ($size['id'] ?? ''),
                'label' => sprintf(
                    '%s · %s GB RAM · %s vCPU · $%s/month',
                    (string) ($size['label'] ?? $size['id'] ?? ''),
                    $this->number(((float) ($size['memory'] ?? 0)) / 1024),
                    (string) ($size['vcpus'] ?? '?'),
                    $this->number((float) (is_array($size['price'] ?? null) ? ($size['price']['monthly'] ?? 0) : 0)),
                ),
            ])),
            'images' => $this->sort(collect($client->images())
                ->filter(fn (array $image): bool => str_contains(strtolower((string) ($image['label'] ?? '')), 'ubuntu'))
                ->map(fn (array $image): array => ['id' => (string) ($image['id'] ?? ''), 'label' => (string) ($image['label'] ?? '')])),
        ];
    }

    /**
     * Normalize Lightsail availability zones, Linux bundles and Ubuntu blueprints for server-selection controls.
     *
     * @param  ServerProvider  $client  The Lightsail adapter supplying catalog responses.
     * @param  list<array<string, mixed>>  $sizes  The provider's size catalog, read once.
     * @param  list<array<string, mixed>>  $sizes  The provider\'s size catalog, read once.
     * @return array{regions: list<array{id: string, label: string}>, sizes: list<array{id: string, label: string}>, images: list<array{id: string, label: string}>}
     */
    private function lightsail(ServerProvider $client, array $sizes): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $zone): array => ['id' => (string) ($zone['id'] ?? ''), 'label' => (string) ($zone['label'] ?? '')])),
            'sizes' => $this->sort(collect($sizes)->map(fn (array $bundle): array => [
                'id' => (string) ($bundle['bundleId'] ?? ''),
                'label' => sprintf('%s · %s GB RAM · %s vCPU · $%s/month', (string) ($bundle['name'] ?? $bundle['bundleId'] ?? ''), $this->number((float) ($bundle['ramSizeInGb'] ?? 0)), (string) ($bundle['cpuCount'] ?? '?'), $this->number((float) ($bundle['price'] ?? 0))),
            ])),
            'images' => $this->sort(collect($client->images())
                ->filter(fn (array $blueprint): bool => str_contains(strtolower((string) ($blueprint['name'] ?? '')), 'ubuntu'))
                ->map(fn (array $blueprint): array => ['id' => (string) ($blueprint['blueprintId'] ?? ''), 'label' => trim(((string) ($blueprint['name'] ?? '')).' '.((string) ($blueprint['version'] ?? '')))])),
        ];
    }

    /**
     * Drop entries without an ID or label and sorts the rest naturally by label.
     *
     * @param  Collection<array-key, array{id: string, label: string}>  $items
     * @return list<array{id: string, label: string}>
     */
    private function sort(Collection $items): array
    {
        return array_values($items
            ->filter(fn (array $item): bool => filled($item['id']) && filled($item['label']))
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->all());
    }

    /**
     * Format a catalog quantity with at most two fractional digits.
     *
     * @param  float  $value  The capacity or price value to display.
     * @return string A decimal string without trailing fractional zeroes or grouping separators.
     */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Models\Provider;
use Illuminate\Support\Collection;

class ServerCatalog
{
    /**
     * @return array{regions: array<int, array{id: string, label: string}>, sizes: array<int, array{id: string, label: string}>, images: array<int, array{id: string, label: string}>}
     */
    public function for(Provider $provider, ServerProvider $client): array
    {
        return match ($provider->provider) {
            Provider::TYPE_HETZNER => $this->hetzner($client),
            Provider::TYPE_VULTR => $this->vultr($client),
            default => $this->digitalOcean($client),
        };
    }

    /**
     * Normalize DigitalOcean regions, sizes and Ubuntu images for server-selection controls.
     *
     * @param  ServerProvider  $client  The DigitalOcean adapter supplying catalog responses.
     * @return array{regions: array, sizes: array, images: array} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function digitalOcean(ServerProvider $client): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())
                ->filter(fn (array $region): bool => (bool) ($region['available'] ?? true))
                ->map(fn (array $region): array => [
                    'id' => (string) ($region['slug'] ?? ''),
                    'label' => (string) ($region['name'] ?? $region['slug'] ?? ''),
                ])),
            'sizes' => $this->sort(collect($client->sizes())->map(fn (array $size): array => [
                'id' => (string) ($size['slug'] ?? ''),
                'label' => sprintf(
                    '%s · %s GB RAM · %s vCPU · $%s/month',
                    (string) ($size['description'] ?? $size['slug'] ?? ''),
                    $this->number(((float) ($size['memory'] ?? 0)) / 1024),
                    (string) ($size['vcpus'] ?? '?'),
                    $this->number((float) ($size['price_monthly'] ?? 0)),
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
     * @return array{regions: array, sizes: array, images: array} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function hetzner(ServerProvider $client): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $region): array => [
                'id' => (string) ($region['name'] ?? ''),
                'label' => trim((string) ($region['city'] ?? $region['name'] ?? '').', '.(string) ($region['country'] ?? '')),
            ])),
            'sizes' => $this->sort(collect($client->sizes())->map(fn (array $size): array => [
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
     * @return array{regions: array, sizes: array, images: array} Sorted identifier/label choices, with provider-specific capacity and price details.
     */
    private function vultr(ServerProvider $client): array
    {
        return [
            'regions' => $this->sort(collect($client->regions())->map(fn (array $region): array => [
                'id' => (string) ($region['id'] ?? ''),
                'label' => trim((string) ($region['city'] ?? $region['id'] ?? '').', '.(string) ($region['country'] ?? '')),
            ])),
            'sizes' => $this->sort(collect($client->sizes())->map(fn (array $size): array => [
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

    /** @return array<int, array{id: string, label: string}> */
    private function sort(Collection $items): array
    {
        return $items
            ->filter(fn (array $item): bool => filled($item['id']) && filled($item['label']))
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
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

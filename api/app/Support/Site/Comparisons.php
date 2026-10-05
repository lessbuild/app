<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Platform\Catalog\Tier;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;

/** The comparison pages' shared facts: each tool in brief, and where BuildPusher's matching services start. */
final class Comparisons
{
    /**
     * Create a new Comparisons instance.
     *
     * @param  ServiceRegistry  $services  The platform's services, for their names and tiers.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Every tool we compare with, in brief: its name, what it is, its summary and the services it overlaps.
     *
     * @return list<array{slug: string, name: string, what: string, summary: string, overlaps: list<array{key: string, name: string}>}>
     */
    public function all(): array
    {
        $list = [];
        foreach ((array) config('compare.competitors') as $slug => $copy) {
            $list[] = [
                'slug' => (string) $slug,
                'name' => (string) $copy['name'],
                'what' => __((string) ($copy['what'] ?? '')),
                'summary' => __((string) $copy['summary']),
                'overlaps' => $this->overlaps((array) ($copy['overlaps'] ?? [])),
            ];
        }

        return $list;
    }

    /**
     * The services a tool overlaps, by key and name, skipping any that don't exist.
     *
     * @param  array<int, mixed>  $keys
     * @return list<array{key: string, name: string}>
     */
    public function overlaps(array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            $service = $this->services->find((string) $key);
            if ($service !== null) {
                $found[] = ['key' => $service->key(), 'name' => $service->name()];
            }
        }

        return $found;
    }

    /**
     * Where each overlapping service's paid tiers start, as a sentence: "Deploy from $9 a month, Monitoring from $29 a
     * month". Infrastructure is included with Deploy.
     *
     * @param  array<int, mixed>  $keys
     * @return string
     */
    public function startingPrices(array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $service = $this->services->find((string) $key);
            if (! $service instanceof PlatformService) {
                continue;
            }
            $paid = array_values(array_filter($service->billing()->tiers, fn (Tier $tier): bool => ($tier->monthlyCents ?? 0) > 0));
            $parts[] = $paid === []
                ? __(':service is included', ['service' => $service->name()])
                : __(':service from :price a month', ['service' => $service->name(), 'price' => '$'.number_format(($paid[0]->monthlyCents ?? 0) / 100)]);
        }

        return implode(', ', $parts);
    }
}

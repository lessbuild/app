<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Suggests a better-fitting size for each cloud server from its last fourteen days of CPU and memory: bigger when the
 * busiest 5% of the time runs it hot, smaller (with the saving) when even that leaves most of it idle. The suggestion
 * keeps headroom: at most 60% CPU and 70% memory at the same load.
 */
final class ServerRightsizing
{
    /**
     * How many days of readings are judged.
     *
     * @var int
     */
    public const DAYS = 14;

    /**
     * The fewest readings needed to judge (about three days at one a minute).
     *
     * @var int
     */
    public const MINIMUM_READINGS = 4000;

    /**
     * Create a new ServerRightsizing instance.
     *
     * @param  ServerProviderResolver  $providers  Reads each provider's sizes.
     * @param  ServerPricing  $pricing  Prices a size.
     */
    public function __construct(private readonly ServerProviderResolver $providers, private readonly ServerPricing $pricing) {}

    /**
     * Suggest sizes for the account's cloud servers that need one.
     *
     * @param  iterable<Server>  $servers
     * @return list<array{server: Server, direction: string, cpu: int, memory: int, current: array{id: string, vcpus: float, memory_gb: float, price: float|null}, suggested: array{id: string, vcpus: float, memory_gb: float, price: float|null}, saving: float|null}>
     */
    public function suggestions(iterable $servers): array
    {
        $suggestions = [];
        foreach ($servers as $server) {
            $suggestion = $this->forServer($server);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Suggest a size for one server, or null when it fits, has too little history, or its provider's sizes can't be
     * read.
     *
     * @param  Server  $server
     * @return array{server: Server, direction: string, cpu: int, memory: int, current: array{id: string, vcpus: float, memory_gb: float, price: float|null}, suggested: array{id: string, vcpus: float, memory_gb: float, price: float|null}, saving: float|null}|null
     */
    public function forServer(Server $server): ?array
    {
        $provider = $server->provider;
        if ($provider === null || ! $provider->type->hostsServers() || $server->size === null) {
            return null;
        }
        $since = now()->subDays(self::DAYS);
        $readings = ServerMetric::query()->where('server_id', $server->id)->where('recorded_at', '>=', $since)->count();
        if ($readings < self::MINIMUM_READINGS) {
            return null;
        }
        $cpu = $this->percentile($server, 'cpu_percent', $readings);
        $memory = $this->percentile($server, 'memory_percent', $readings);
        $sizes = $this->sizes($server);
        $current = collect($sizes)->firstWhere('id', $server->size);
        if ($current === null) {
            return null;
        }
        $direction = match (true) {
            $cpu >= 85 || $memory >= 90 => 'up',
            $cpu <= 30 && $memory <= 45 => 'down',
            default => null,
        };
        if ($direction === null) {
            return null;
        }
        $needCpu = $current['vcpus'] * $cpu / 100 / 0.6;
        $needMemory = $current['memory_gb'] * $memory / 100 / 0.7;
        $fits = collect($sizes)->filter(fn (array $size): bool => $size['id'] !== $current['id'] && $size['price'] !== null && $size['vcpus'] >= $needCpu && $size['memory_gb'] >= $needMemory)
            ->filter(fn (array $size): bool => $direction === 'up'
                ? $size['vcpus'] >= $current['vcpus'] && $size['memory_gb'] >= $current['memory_gb']
                : $current['price'] === null || $size['price'] < $current['price'])
            ->sortBy('price')->first();
        if ($fits === null) {
            return null;
        }

        return [
            'server' => $server, 'direction' => $direction, 'cpu' => $cpu, 'memory' => $memory,
            'current' => $current, 'suggested' => $fits,
            'saving' => $current['price'] !== null ? round($current['price'] - (float) $fits['price'], 2) : null,
        ];
    }

    /**
     * Read the value a metric stays under 95% of the time over the period.
     *
     * @param  Server  $server
     * @param  string  $column  cpu_percent or memory_percent
     * @param  int  $readings
     * @return int
     */
    private function percentile(Server $server, string $column, int $readings): int
    {
        $value = ServerMetric::query()->where('server_id', $server->id)->where('recorded_at', '>=', now()->subDays(self::DAYS))
            ->orderBy($column)->offset((int) floor($readings * 0.95))->limit(1)->value($column);

        return (int) $value;
    }

    /**
     * Get the provider's sizes for the server's region, normalised, cached for six hours per provider.
     *
     * @param  Server  $server
     * @return list<array{id: string, vcpus: float, memory_gb: float, price: float|null}>
     */
    private function sizes(Server $server): array
    {
        $provider = $server->provider;
        if ($provider === null) {
            return [];
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = Cache::remember('rightsizing.sizes.'.$provider->id, now()->addHours(6), function () use ($provider): array {
            try {
                return $this->providers->resolve($provider)->sizes();
            } catch (Throwable) {
                return [];
            }
        });
        $sizes = [];
        foreach ($raw as $entry) {
            [$id, $vcpus, $memory] = match ($provider->type) {
                ProviderType::Hetzner => [$entry['name'] ?? null, $entry['cores'] ?? null, $entry['memory'] ?? null],
                ProviderType::Vultr => [$entry['id'] ?? null, $entry['vcpu_count'] ?? null, isset($entry['ram']) ? (float) $entry['ram'] / 1024 : null],
                ProviderType::Linode => [$entry['id'] ?? null, $entry['vcpus'] ?? null, isset($entry['memory']) ? (float) $entry['memory'] / 1024 : null],
                ProviderType::Lightsail => [$entry['bundleId'] ?? null, $entry['cpuCount'] ?? null, $entry['ramSizeInGb'] ?? null],
                default => [$entry['slug'] ?? null, $entry['vcpus'] ?? null, isset($entry['memory']) ? (float) $entry['memory'] / 1024 : null],
            };
            if (! is_string($id) || ! is_numeric($vcpus) || ! is_numeric($memory)) {
                continue;
            }
            $sizes[] = ['id' => $id, 'vcpus' => (float) $vcpus, 'memory_gb' => round((float) $memory, 2), 'price' => $this->pricing->price($provider->type, $raw, $id, $server->region)];
        }

        return $sizes;
    }
}

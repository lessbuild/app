<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Data\Admin\QueueState;
use App\Platform\ServiceRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The public platform status (Core's `/status` and `/status/report.json`, same shape): coarse components with nothing
 * internal: the application and its database, background processing, and each service by the queues its work runs on.
 * Cached for 30 seconds.
 */
final class PlatformStatus
{
    /**
     * Create a new PlatformStatus instance.
     *
     * Reads the platform's public status.
     *
     * @param  SystemHealth  $health  Reads queue backlogs.
     * @param  ServiceRegistry  $services  Lists the services.
     */
    public function __construct(private readonly SystemHealth $health, private readonly ServiceRegistry $services) {}

    /**
     * Check every part now, without the 30-second cache, for the status history.
     *
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{key: string, name: string, description: string, status: string, operational: bool}>}
     */
    public function fresh(): array
    {
        return $this->build();
    }

    /**
     * Get the status: overall, when it was checked, and each component.
     *
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{key: string, name: string, description: string, status: string, operational: bool}>}
     */
    public function snapshot(): array
    {
        /** @var array{status: string, operational: bool, checked_at: string, components: list<array{key: string, name: string, description: string, status: string, operational: bool}>} */
        return Cache::remember('platform:status', 30, fn (): array => $this->build());
    }

    /**
     * Check each component now.
     *
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{key: string, name: string, description: string, status: string, operational: bool}>}
     */
    private function build(): array
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }
        $queues = collect($database ? $this->health->queues() : [])->keyBy(fn (QueueState $queue): string => $queue->queue);
        $heartbeat = Cache::get(SystemHealth::HEARTBEAT_KEY);
        $scheduler = is_int($heartbeat) && now()->getTimestamp() - $heartbeat <= 180;
        $background = $database && $scheduler && $queues->every(fn (QueueState $queue): bool => $queue->healthy);
        $components = [
            $this->component('app', __('Dashboard and API'), __('Signing in, the dashboard and the API.'), $database),
            $this->component('background', __('Background processing'), __('Scheduled work, emails and queued jobs.'), $background),
        ];
        foreach ($this->services->all() as $service) {
            $own = (array) config('platform.service_queues.'.$service->key(), ['default']);
            $healthy = $database && $scheduler && collect($own)->every(fn (mixed $name): bool => $queues->get((string) $name)->healthy ?? true);
            $components[] = $this->component($service->key(), $service->name(), $service->tagline(), $healthy);
        }
        $operational = collect($components)->every(fn (array $component): bool => $component['operational']);

        return ['status' => $operational ? 'operational' : 'degraded', 'operational' => $operational, 'checked_at' => now()->utc()->toIso8601String(), 'components' => $components];
    }

    /**
     * Describe one component.
     *
     * @param  string  $key  A stable key for the part, such as "app" or a service's key.
     * @param  string  $name
     * @param  string  $description
     * @param  bool  $operational
     * @return array{key: string, name: string, description: string, status: string, operational: bool}
     */
    private function component(string $key, string $name, string $description, bool $operational): array
    {
        return ['key' => $key, 'name' => $name, 'description' => $description, 'status' => $operational ? __('Operational') : __('Degraded'), 'operational' => $operational];
    }
}

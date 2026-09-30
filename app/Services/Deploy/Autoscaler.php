<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Environment;
use App\Models\ServerMetric;
use Carbon\CarbonImmutable;

/**
 * Scales environments that ask for it by their servers' CPU: one replica more when the average over the last five
 * minutes is above the target (at most every three minutes), one fewer when the last ten minutes stayed under half of
 * it (at most every ten), always between the minimum and maximum. Hibernated environments are left asleep.
 */
final class Autoscaler
{
    /**
     * Check every environment with automatic scaling on, and change replicas where the CPU says so.
     *
     * @return array<string, int> the environments changed, by ID, with their new replica count
     */
    public function run(): array
    {
        $changed = [];
        $now = CarbonImmutable::now();
        Environment::query()->where('autoscale_enabled', true)->whereNull('hibernated_at')->orderBy('id')->each(function (Environment $environment) use ($now, &$changed): void {
            $servers = collect($environment->deployedWebsites())->pluck('server')->filter()->pluck('id')->unique()->values();
            if ($servers->isEmpty()) {
                return;
            }
            $recent = $this->averageCpu($servers->all(), $now->subMinutes(5));
            $calm = $this->averageCpu($servers->all(), $now->subMinutes(10));
            $last = $environment->autoscaled_at;
            $target = max(20, min(95, $environment->autoscale_cpu_target));
            $replicas = $environment->desired_replicas;
            if ($recent !== null && $recent > $target && $replicas < $environment->maximum_replicas && ($last === null || $last->lte($now->subMinutes(3)))) {
                $replicas++;
            } elseif ($calm !== null && $calm < $target / 2 && $replicas > $environment->minimum_replicas && ($last === null || $last->lte($now->subMinutes(10)))) {
                $replicas--;
            } else {
                return;
            }
            $environment->forceFill(['desired_replicas' => $replicas, 'autoscaled_at' => $now])->save();
            ApplyEnvironmentRuntime::dispatch($environment->id, false);
            $changed[$environment->id] = $replicas;
        });

        return $changed;
    }

    /**
     * Get the servers' average CPU since a moment, or null without enough readings (at least three).
     *
     * @param  list<int>  $serverIds
     * @param  CarbonImmutable  $since
     * @return float|null
     */
    private function averageCpu(array $serverIds, CarbonImmutable $since): ?float
    {
        $readings = ServerMetric::query()->whereIn('server_id', $serverIds)->where('recorded_at', '>=', $since)->toBase()
            ->selectRaw('COUNT(*) AS readings, AVG(cpu_percent) AS cpu')->first();

        return $readings !== null && (int) $readings->readings >= 3 ? (float) $readings->cpu : null;
    }
}

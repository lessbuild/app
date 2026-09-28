<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Data\Infrastructure\ServerCost;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Website;
use Illuminate\Support\Collection;

/** The account's servers with their monthly cost, the last hour's CPU, and the projects using them, plus totals by currency. */
final class InfrastructureCostsQuery
{
    /**
     * The costs page: each server's monthly cost, CPU over the last hour, website count, idle flag and the projects it
     * serves, plus totals per currency and how many servers have no known cost or look idle.
     *
     * @param  string  $accountId
     * @return array{rows: Collection<int, ServerCost>, totals: array<string, float>, unknown: int, idle: int}
     */
    public function handle(string $accountId): array
    {
        $servers = Server::query()->where('account_id', $accountId)->with('provider')->withCount('websites')->orderBy('name')->get();
        $cpu = ServerMetric::query()->whereIn('server_id', $servers->modelKeys())->where('recorded_at', '>=', now()->subHour())
            ->groupBy('server_id')->selectRaw('server_id, AVG(cpu_percent) as average, COUNT(*) as samples')->get()->keyBy('server_id');
        $projects = Website::query()->whereIn('server_id', $servers->modelKeys())->whereNotNull('environment_id')->with('environment.project')->get()
            ->groupBy('server_id')->map(fn (Collection $websites): array => array_values($websites->map(fn (Website $website): ?string => $website->environment?->project->name)->filter()->unique()->sort()->all()));

        $rows = $servers->map(function (Server $server) use ($cpu, $projects): ServerCost {
            $reading = $cpu->get($server->id);
            $average = $reading === null ? null : round((float) $reading->getAttribute('average'), 1);
            $samples = $reading === null ? 0 : (int) $reading->getAttribute('samples');

            return new ServerCost(
                server: $server,
                monthly: $server->monthly_cost,
                averageCpu: $average,
                websites: (int) $server->getAttribute('websites_count'),
                idle: (int) $server->getAttribute('websites_count') === 0 || ($samples >= 6 && $average !== null && $average < 10),
                projects: $projects->get($server->id, []),
            );
        });
        $totals = [];
        foreach ($rows as $row) {
            if ($row->monthly !== null) {
                $currency = $row->server->monthly_cost_currency ?? 'USD';
                $totals[$currency] = round(($totals[$currency] ?? 0) + $row->monthly, 2);
            }
        }

        return ['rows' => $rows, 'totals' => $totals, 'unknown' => $rows->whereNull('monthly')->count(), 'idle' => $rows->where('idle', true)->count()];
    }
}

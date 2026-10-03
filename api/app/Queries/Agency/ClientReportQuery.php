<?php

declare(strict_types=1);

namespace App\Queries\Agency;

use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Client;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Queries\Billing\BillingOverviewQuery;
use App\Queries\Billing\CostViewQuery;
use Carbon\CarbonImmutable;

/**
 * A client's month: for each of their projects, uptime from its checks, incidents, live deploys and visitors, and
 * what it cost with the agency's markup.
 */
final class ClientReportQuery
{
    /**
     * Create a new ClientReportQuery instance.
     *
     * @param  BillingOverviewQuery  $billing  The account's plans, for the costs.
     * @param  CostViewQuery  $costs  Costs by project.
     */
    public function __construct(private readonly BillingOverviewQuery $billing, private readonly CostViewQuery $costs) {}

    /**
     * Build the report for a month (YYYY-MM).
     *
     * @param  Client  $client
     * @param  string  $month
     * @return array{month: string, label: string, currency: string, projects: list<array{name: string, uptime: float|null, incidents: int, deploys: int, visitors: int|null, cost: array<string, float>}>, total: array<string, float>}
     */
    public function handle(Client $client, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC') ?: CarbonImmutable::now('UTC')->startOfMonth();
        $end = $start->endOfMonth();
        $costs = $this->costs->handle($client->account, $this->billing->handle($client->account));
        $byProject = collect($costs['projects'])->keyBy(fn (array $row): string => $row['project']->id);
        $factor = 1 + $client->markup_percent / 100;
        $rows = [];
        $total = [];
        foreach ($client->projects() as $project) {
            $environmentIds = $project->environments()->pluck('id')->all();
            $monitorIds = Monitor::withTrashed()->whereIn('environment_id', $environmentIds)->pluck('id')->all();
            $checks = MonitorCheck::query()->whereIn('monitor_id', $monitorIds)->where('status', 'completed')->whereIn('outcome', ['up', 'down'])
                ->whereBetween('scheduled_at', [$start, $end])->toBase()->selectRaw("SUM(CASE WHEN outcome = 'up' THEN 1 ELSE 0 END) AS up, COUNT(*) AS measured")->first();
            $measured = (int) ($checks->measured ?? 0);
            $siteIds = AnalyticsSite::query()->where('project_id', $project->id)->pluck('id')->all();
            $cost = [];
            $row = $byProject->get($project->id);
            if ($row !== null) {
                $cost[$costs['currency']] = $row['platform'];
                foreach ($row['cloud'] as $currency => $amount) {
                    $cost[$currency] = ($cost[$currency] ?? 0) + $amount;
                }
            }
            $cost = array_map(fn (float $amount): float => round($amount * $factor, 2), array_filter($cost, fn (float $amount): bool => $amount > 0));
            foreach ($cost as $currency => $amount) {
                $total[$currency] = round(($total[$currency] ?? 0) + $amount, 2);
            }
            $rows[] = [
                'name' => $project->name,
                'uptime' => $measured === 0 ? null : round(100 * (int) ($checks->up ?? 0) / $measured, 3),
                'incidents' => Incident::query()->where('project_id', $project->id)->whereBetween('opened_at', [$start, $end])->count(),
                'deploys' => Build::query()->whereIn('environment_id', $environmentIds)->where('status', Build::STATUS_SUCCEEDED)->whereBetween('created_at', [$start, $end])->count(),
                'visitors' => $siteIds === [] ? null : (int) AnalyticsDailyAggregate::query()->whereIn('site_id', $siteIds)->where('dimension', 'all')
                    ->whereBetween('local_date', [$start->toDateString(), $end->toDateString().' 23:59:59'])->sum('visitors'),
                'cost' => $cost,
            ];
        }

        return ['month' => $start->format('Y-m'), 'label' => $start->translatedFormat('F Y'), 'currency' => $costs['currency'], 'projects' => $rows, 'total' => $total];
    }
}

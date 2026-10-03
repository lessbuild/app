<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Incident;
use App\Models\MonitorCheck;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use Carbon\CarbonImmutable;

/** A status page's uptime for one calendar month (UTC): each component's uptime, its incidents and total downtime. */
final class MonthlyUptimeQuery
{
    /**
     * Build the month's report. Uptime counts passed and failed checks (unknown ones don't count either way); the
     * overall figure is the average of the components that have data; downtime adds up the incidents, cut to the month.
     *
     * @param  StatusPage  $page
     * @param  CarbonImmutable  $month  any moment in the month
     * @return array{month: string, label: string, uptime: float|null, downtime_minutes: int, components: list<array{name: string, group: string|null, uptime: float|null, checks: int}>, incidents: list<array{title: string, component: string, opened_at: CarbonImmutable, resolved_at: CarbonImmutable|null, minutes: int}>}
     */
    public function handle(StatusPage $page, CarbonImmutable $month): array
    {
        $start = $month->utc()->startOfMonth();
        $end = $start->endOfMonth();
        $until = $end->min(CarbonImmutable::now('UTC'));
        $page->loadMissing('components.monitor');
        $components = $page->components->filter(fn (StatusPageComponent $component): bool => $component->monitor !== null)->values();
        $monitorIds = $components->pluck('monitor_id')->map(fn (mixed $id): int => (int) $id)->all();
        $counts = MonitorCheck::query()->whereIn('monitor_id', $monitorIds)->where('status', 'completed')->whereIn('outcome', ['up', 'down'])
            ->whereBetween('scheduled_at', [$start, $until])->toBase()
            ->selectRaw("monitor_id, SUM(CASE WHEN outcome = 'up' THEN 1 ELSE 0 END) AS up, COUNT(*) AS measured")->groupBy('monitor_id')->get()->keyBy('monitor_id');

        $rows = [];
        foreach ($components as $component) {
            $count = $counts->get($component->monitor_id);
            $measured = (int) ($count->measured ?? 0);
            $rows[] = ['name' => $component->label, 'group' => $component->group_name, 'uptime' => $measured > 0 ? round((int) ($count->up ?? 0) / $measured * 100, 3) : null, 'checks' => $measured];
        }
        $names = $components->pluck('label', 'monitor_id');
        $incidents = [];
        $downtime = 0;
        $found = Incident::query()->where('account_id', $page->account_id)->whereIn('monitor_id', $monitorIds)
            ->where('opened_at', '<=', $until)->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start))
            ->orderBy('opened_at')->limit(200)->get(['monitor_id', 'title', 'opened_at', 'resolved_at']);
        foreach ($found as $incident) {
            $from = $incident->opened_at->max($start);
            $to = ($incident->resolved_at ?? $until)->min($until);
            $minutes = (int) max(0, $from->diffInMinutes($to));
            $downtime += $minutes;
            $incidents[] = ['title' => $incident->title, 'component' => (string) $names->get($incident->monitor_id, ''), 'opened_at' => $incident->opened_at, 'resolved_at' => $incident->resolved_at, 'minutes' => $minutes];
        }
        $measured = array_values(array_filter(array_column($rows, 'uptime'), fn (?float $uptime): bool => $uptime !== null));

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->isoFormat('MMMM YYYY'),
            'uptime' => $measured === [] ? null : round(array_sum($measured) / count($measured), 3),
            'downtime_minutes' => $downtime,
            'components' => $rows,
            'incidents' => $incidents,
        ];
    }
}

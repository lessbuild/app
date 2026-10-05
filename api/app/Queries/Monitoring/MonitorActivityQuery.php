<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MonitorActivityQuery
{
    /**
     * How many bars the last-day strip has.
     *
     * @var int
     */
    public const BARS = 48;

    /**
     * How many minutes each bar of the strip covers.
     *
     * @var int
     */
    public const BAR_MINUTES = 30;

    /**
     * How many days uptime and incidents are counted over.
     *
     * @var int
     */
    public const DAYS = 30;

    /**
     * Describe how monitors have done lately: uptime over the last 30 days, the average response time over the last
     * day, the incidents opened in the last 30 days, and the last day as 48 half-hour bars (up, degraded when some
     * checks failed, down, or none without readings). Monitors that are checked from outside go by their checks;
     * heartbeat and queue monitors, which report in themselves, by their incidents.
     *
     * @param  list<Monitor>  $monitors
     * @return array<int, array{uptime: float|null, latencyMs: int|null, incidents: int, strip: list<string>}>
     */
    public function handle(array $monitors): array
    {
        if ($monitors === []) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $since = $now->subDays(self::DAYS);
        $start = $now->subMinutes(self::BARS * self::BAR_MINUTES);
        $ids = array_map(fn (Monitor $monitor): int => $monitor->id, $monitors);
        $results = fn () => MonitorCheck::query()->whereIn('monitor_id', $ids)->where('status', 'completed')->whereIn('outcome', ['up', 'down']);
        $counts = $results()->where('scheduled_at', '>=', $since)->toBase()
            ->selectRaw("monitor_id, SUM(CASE WHEN outcome = 'up' THEN 1 ELSE 0 END) AS up, COUNT(*) AS measured")->groupBy('monitor_id')->get()->keyBy('monitor_id');
        $latency = $results()->where('outcome', 'up')->whereNotNull('duration_ms')->where('scheduled_at', '>=', $now->subDay())->toBase()
            ->selectRaw('monitor_id, AVG(duration_ms) AS latency')->groupBy('monitor_id')->pluck('latency', 'monitor_id');
        $recent = $results()->where('scheduled_at', '>=', $start)->get(['monitor_id', 'outcome', 'scheduled_at'])->groupBy('monitor_id');
        $incidents = Incident::query()->whereIn('monitor_id', $ids)->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $since))
            ->get(['monitor_id', 'opened_at', 'resolved_at'])->groupBy('monitor_id');

        $activity = [];
        foreach ($monitors as $monitor) {
            $count = $counts->get($monitor->id);
            $measured = (int) ($count->measured ?? 0);
            /** @var Collection<int, Incident> $own */
            $own = $incidents->get($monitor->id) ?? collect();
            /** @var Collection<int, MonitorCheck> $checks */
            $checks = $recent->get($monitor->id) ?? collect();
            $signals = in_array($monitor->type, ['heartbeat', 'queue'], true);
            $activity[$monitor->id] = [
                'uptime' => $signals ? $this->uptimeFromIncidents($monitor, $own, $since, $now) : ($measured > 0 ? round((int) ($count->up ?? 0) / $measured * 100, 2) : null),
                'latencyMs' => is_numeric($latency->get($monitor->id)) ? (int) round((float) $latency->get($monitor->id)) : null,
                'incidents' => $own->filter(fn (Incident $incident): bool => $incident->opened_at->greaterThanOrEqualTo($since))->count(),
                'strip' => $this->strip($monitor, $checks, $own, $start, $signals),
            ];
        }

        return $activity;
    }

    /**
     * Turn the last day into bars: from the checks in each half hour, or (for monitors that report in) from whether an
     * incident was open, once the monitor existed and was running.
     *
     * @param  Monitor  $monitor
     * @param  Collection<int, MonitorCheck>  $checks
     * @param  Collection<int, Incident>  $incidents
     * @param  CarbonImmutable  $start
     * @param  bool  $signals  whether the monitor reports in itself
     * @return list<string>
     */
    private function strip(Monitor $monitor, Collection $checks, Collection $incidents, CarbonImmutable $start, bool $signals): array
    {
        $bars = [];
        for ($bar = 0; $bar < self::BARS; $bar++) {
            $from = $start->addMinutes($bar * self::BAR_MINUTES);
            $until = $from->addMinutes(self::BAR_MINUTES);
            $outcomes = $checks->filter(fn (MonitorCheck $check): bool => $check->scheduled_at->greaterThanOrEqualTo($from) && $check->scheduled_at->lessThan($until))->pluck('outcome')->unique();
            $open = $incidents->contains(fn (Incident $incident): bool => $incident->opened_at->lessThan($until) && ($incident->resolved_at === null || $incident->resolved_at->greaterThan($from)));
            $bars[] = match (true) {
                $outcomes->count() > 1 => 'degraded',
                $outcomes->first() === 'down' => 'down',
                $outcomes->first() === 'up' => 'up',
                $signals && $monitor->enabled && $monitor->created_at !== null && $monitor->created_at->lessThan($until) => $open ? 'down' : 'up',
                default => 'none',
            };
        }

        return $bars;
    }

    /**
     * The share of the period, since the monitor was made, without an open incident, as a percentage.
     *
     * @param  Monitor  $monitor
     * @param  Collection<int, Incident>  $incidents
     * @param  CarbonImmutable  $since
     * @param  CarbonImmutable  $now
     * @return float|null
     */
    private function uptimeFromIncidents(Monitor $monitor, Collection $incidents, CarbonImmutable $since, CarbonImmutable $now): ?float
    {
        $from = $monitor->created_at !== null && $monitor->created_at->greaterThan($since) ? CarbonImmutable::parse($monitor->created_at) : $since;
        $window = $from->diffInSeconds($now, true);
        if ($window <= 0) {
            return null;
        }
        $down = $incidents->sum(fn (Incident $incident): float => max(0, $incident->opened_at->max($from)->diffInSeconds(($incident->resolved_at ?? $now)->min($now), false)));

        return round(max(0, 1 - $down / $window) * 100, 2);
    }
}

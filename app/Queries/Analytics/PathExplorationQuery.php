<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Path exploration: where visitors went before and after a page, and the most common first pages to start from. */
final class PathExplorationQuery
{
    /**
     * Get the ten pages most often viewed just before and just after a page within the same visitor's day, with
     * "(entrance)" for arrivals and "(exit)" for departures, and the ten most viewed pages to start from.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  string|null  $path  the page to explore; null lists starting points only
     * @return array{starts: list<array{label: string, value: int}>, previous: list<array{label: string, value: int}>, next: list<array{label: string, value: int}>, views: int}
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period, ?string $path): array
    {
        $pageviews = AnalyticsEvent::query()->where('site_id', $site->id)->countable()->where('type', 'pageview')
            ->whereBetween('occurred_at', [$period->start->utc(), $period->end->utc()]);
        $starts = (clone $pageviews)->toBase()->selectRaw('path AS label, COUNT(*) AS value')->groupBy('path')
            ->orderByDesc('value')->orderBy('path')->limit(10)->get();
        if ($path === null) {
            return ['starts' => $this->rows($starts), 'previous' => [], 'next' => [], 'views' => 0];
        }

        $visitor = 'COALESCE(session_id, visitor_hash, CAST(event_id AS TEXT))';
        $steps = (clone $pageviews)->toBase()->select('path')
            ->selectRaw("LAG(path) OVER (PARTITION BY {$visitor} ORDER BY occurred_at, id) AS previous_path")
            ->selectRaw("LEAD(path) OVER (PARTITION BY {$visitor} ORDER BY occurred_at, id) AS next_path");
        $around = fn (bool $next) => DB::query()->fromSub($steps, 'steps')->where('path', $path)
            ->selectRaw($next ? 'COALESCE(next_path, ?) AS label, COUNT(*) AS value' : 'COALESCE(previous_path, ?) AS label, COUNT(*) AS value', [$next ? '(exit)' : '(entrance)'])
            ->groupBy('label')->orderByDesc('value')->orderBy('label')->limit(10)->get();

        return [
            'starts' => $this->rows($starts),
            'previous' => $this->rows($around(false)),
            'next' => $this->rows($around(true)),
            'views' => (clone $pageviews)->where('path', $path)->count(),
        ];
    }

    /**
     * Turn result rows into label and value pairs.
     *
     * @param  iterable<stdClass>  $rows
     * @return list<array{label: string, value: int}>
     */
    private function rows(iterable $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = ['label' => (string) $row->label, 'value' => (int) $row->value];
        }

        return $list;
    }
}

<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Click maps: which pages get clicks, and on one page what's clicked and where. */
final class ClickMapQuery
{
    /**
     * Get the pages with the most clicks and, for the chosen page, the most clicked elements and up to 2,000 click
     * positions (percent of the page's width and height) to plot.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  string|null  $path
     * @return array{pages: list<array{label: string, value: int}>, targets: list<array{label: string, value: int}>, points: list<array{x: float, y: float}>}
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period, ?string $path): array
    {
        $clicks = fn () => AnalyticsEvent::query()->where('site_id', $site->id)->countable()->where('type', 'click')
            ->whereBetween('occurred_at', [$period->start->utc(), $period->end->utc()]);
        $pgsql = DB::getDriverName() === 'pgsql';
        $target = $pgsql ? "properties->>'target'" : "json_extract(properties, '$.target')";
        $pages = $this->rank($clicks(), 'path');
        if ($path === null) {
            return ['pages' => $pages, 'targets' => [], 'points' => []];
        }
        $points = [];
        foreach ($clicks()->where('path', $path)->latest('id')->limit(2000)->get(['properties']) as $click) {
            $points[] = ['x' => (float) ($click->properties['x'] ?? 0), 'y' => (float) ($click->properties['y'] ?? 0)];
        }

        return ['pages' => $pages, 'targets' => $this->rank($clicks()->where('path', $path), $target), 'points' => $points];
    }

    /**
     * Count rows per label, the top fifteen.
     *
     * @param  Builder<AnalyticsEvent>  $query
     * @param  literal-string  $label  a column or SQL expression
     * @return list<array{label: string, value: int}>
     */
    private function rank(Builder $query, string $label): array
    {
        $list = [];
        foreach ($query->toBase()->selectRaw("{$label} AS label, COUNT(*) AS value")->groupBy('label')->orderByDesc('value')->orderBy('label')->limit(15)->get() as $row) {
            $list[] = ['label' => (string) $row->label, 'value' => (int) $row->value];
        }

        return $list;
    }
}

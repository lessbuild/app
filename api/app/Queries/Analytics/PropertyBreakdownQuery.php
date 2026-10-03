<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Custom event property breakdowns: how often each value of a property came with an event, and its revenue. */
final class PropertyBreakdownQuery
{
    /**
     * List the custom events sent in the period (the 20 most frequent) and, for the chosen event and property, its 20
     * most common values with how many times they were sent, by how many visitors, and the revenue they carried.
     * Properties are the ones the site keeps.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  string|null  $event  the event's name
     * @param  string|null  $property  one of the site's kept property names
     * @return array{events: list<array{label: string, value: int}>, values: list<array{label: string, events: int, visitors: int, revenue: float}>}
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period, ?string $event, ?string $property): array
    {
        $events = fn (): Builder => AnalyticsEvent::query()->where('site_id', $site->id)->countable()->where('type', 'event')
            ->whereBetween('occurred_at', [$period->start->utc(), $period->end->utc()]);
        $pgsql = DB::getDriverName() === 'pgsql';
        $name = $pgsql ? "properties->>'name'" : "json_extract(properties, '$.name')";
        $names = [];
        foreach ($events()->toBase()->selectRaw("{$name} AS label, COUNT(*) AS value")->groupBy('label')->orderByDesc('value')->limit(20)->get() as $row) {
            if ($row->label !== null) {
                $names[] = ['label' => (string) $row->label, 'value' => (int) $row->value];
            }
        }
        if ($event === null || $property === null || ! in_array($property, $site->custom_properties ?? [], true) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $property) !== 1) {
            return ['events' => $names, 'values' => []];
        }

        // The property's name is bound, never written into the SQL.
        [$value, $binding] = $pgsql ? ["properties->'props'->>?", $property] : ['json_extract(properties, ?)', '$.props.'.$property];
        $revenue = $pgsql ? "CAST(properties->>'revenue' AS NUMERIC)" : "json_extract(properties, '$.revenue')";
        $values = [];
        foreach ($events()->whereRaw("{$name} = ?", [$event])->whereRaw("{$value} IS NOT NULL", [$binding])->toBase()
            ->selectRaw("{$value} AS label, COUNT(*) AS events, COUNT(DISTINCT visitor_hash) AS visitors, SUM(COALESCE({$revenue}, 0)) AS revenue", [$binding])
            ->groupBy('label')->orderByDesc('events')->orderBy('label')->limit(20)->get() as $row) {
            $values[] = ['label' => (string) $row->label, 'events' => (int) $row->events, 'visitors' => (int) $row->visitors, 'revenue' => round((float) $row->revenue, 2)];
        }

        return ['events' => $names, 'values' => $values];
    }
}

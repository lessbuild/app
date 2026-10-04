<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsSite;
use App\Support\Country;
use Carbon\CarbonInterface;

final class ReportPayload
{
    /**
     * The report's ranked lists in the order the page shows them: the summary key, the title, the text shown while
     * it's empty (blank for lists the tracker only fills when they're switched on, which are left out until then), and
     * the report filter a row narrows the report to (null when its rows can't filter).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private const LISTS = [
        ['pages', 'Top pages', 'Pages appear after the first visit.', 'path'],
        ['contentGroups', 'Content groups', '', 'group'],
        ['entryPages', 'Entry pages', 'Entry pages appear once visits are processed.', 'path'],
        ['exitPages', 'Exit pages', 'Exit pages appear once visits are processed.', 'path'],
        ['channels', 'Channels', 'Channels appear once visitors arrive.', 'channel'],
        ['sources', 'Sources', 'Sources appear once visitors arrive.', null],
        ['countries', 'Countries', 'Countries appear once visitors arrive.', 'country'],
        ['regions', 'Regions', '', 'region'],
        ['cities', 'Cities', '', 'city'],
        ['campaigns', 'Campaigns', 'Campaigns appear after visits tagged with utm_campaign.', 'campaign'],
        ['terms', 'Campaign terms', '', 'term'],
        ['contents', 'Campaign content', '', 'content'],
        ['devices', 'Devices', 'Devices appear once visitors arrive.', 'device'],
        ['browsers', 'Browsers', 'Browsers appear once visitors arrive.', 'browser'],
        ['operatingSystems', 'Operating systems', 'Operating systems appear once visitors arrive.', 'os'],
        ['screenSizes', 'Screen sizes', '', 'screen'],
        ['browserVersions', 'Browser versions', '', null],
        ['osVersions', 'System versions', '', null],
        ['outboundLinks', 'Outbound links', '', null],
        ['fileDownloads', 'File downloads', '', null],
        ['notFound', 'Pages not found', '', null],
        ['searches', 'Site searches', '', null],
        ['emptySearches', 'Searches with no results', '', null],
    ];

    /**
     * Turn AnalyticsReportQuery's report into JSON for the app: dates become ISO 8601 strings, labels are translated,
     * and the ranked lists come in the page's order with their titles and filters.
     *
     * @param  array<string, mixed>  $summary  AnalyticsReportQuery::handle()'s report.
     * @param  AnalyticsSite  $site
     * @return array<string, mixed>
     */
    public static function from(array $summary, AnalyticsSite $site): array
    {
        /** @var ReportPeriod $period */
        $period = $summary['period'];
        $lists = [];
        foreach (self::LISTS as [$key, $title, $empty, $filter]) {
            $items = $summary[$key] ?? [];
            if ($empty === '' && $items === []) {
                continue;
            }
            $lists[] = [
                'key' => $key,
                'title' => __($title),
                'empty' => $empty === '' ? null : __($empty),
                'filter' => $filter,
                'items' => array_map(fn (array $item): array => [
                    'value' => (string) $item['label'],
                    'label' => $filter === 'country' ? Country::label((string) $item['label']) : (string) $item['label'],
                    'count' => (int) $item['value'],
                ], $items),
            ];
        }
        $recent = $summary['recent'] ?? ['visitorCount' => 0, 'events' => []];

        return [
            'period' => self::period($period),
            'timezone' => $site->timezone,
            'granularity' => $summary['granularity'] ?? 'day',
            'metrics' => array_map(fn (array $metric): array => [
                'label' => __($metric['label']),
                'value' => $metric['value'],
                'change' => $metric['change'],
            ], $summary['metrics']),
            'series' => $summary['series'] ?? [],
            'lists' => $lists,
            'engagement' => array_values(array_filter($summary['engagement'] ?? [], fn (array $row): bool => $row['seconds'] !== null || $row['scroll'] !== null)),
            'vitals' => $summary['vitals'] ?? ['samples' => 0, 'metrics' => [], 'slowPages' => []],
            'searchTerms' => $summary['searchTerms'] ?? null,
            'goals' => $summary['goals'] ?? [],
            'recent' => self::live($recent),
            'lastProcessedAt' => $summary['lastProcessedAt'] instanceof CarbonInterface ? $summary['lastProcessedAt']->toIso8601String() : null,
            'hasData' => (bool) ($summary['hasData'] ?? false),
        ];
    }

    /**
     * Describe the report's period: its dates, whether they were chosen by hand, the comparison, and the query string
     * that asks for the same period again.
     *
     * @param  ReportPeriod  $period
     * @return array{days: int, custom: bool, compare: string, start: string, end: string, query: array<string, int|string>}
     */
    public static function period(ReportPeriod $period): array
    {
        return [
            'days' => $period->days,
            'custom' => $period->custom,
            'compare' => $period->compare,
            'start' => $period->start->toDateString(),
            'end' => $period->end->toDateString(),
            'query' => $period->query(),
        ];
    }

    /**
     * Turn LiveVisitorsQuery's "right now" panel into JSON.
     *
     * @param  array{visitorCount: int, events: array<int, array{type: string, path: string|null, occurredAt: CarbonInterface, source: string}>}  $recent
     * @return array{visitorCount: int, events: list<array{type: string, path: string|null, occurredAt: string, source: string}>}
     */
    public static function live(array $recent): array
    {
        return [
            'visitorCount' => $recent['visitorCount'],
            'events' => array_values(array_map(fn (array $event): array => [...$event, 'occurredAt' => $event['occurredAt']->toIso8601String()], $recent['events'])),
        ];
    }
}

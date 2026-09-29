<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class LiveVisitorsQuery
{
    /**
     * Get what's happening on a site right now: how many visitors sent events in the last five minutes, and the
     * latest few of those events, with the report's filters applied.
     *
     * @param  AnalyticsSite  $site
     * @param  array<string, string|null>  $filters
     * @return array{visitorCount: int, events: array<int, array{type: string, path: string, occurredAt: \Illuminate\Support\Carbon, source: string}>}
     */
    public function handle(AnalyticsSite $site, array $filters = []): array
    {
        $now = CarbonImmutable::now()->utc();
        $recent = fn (): Builder => AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->countable()
            ->whereBetween('occurred_at', [$now->subMinutes(5), $now->addMinute()])
            ->matchingReportFilters($filters);

        return [
            'visitorCount' => $recent()->whereNotNull('visitor_hash')->distinct()->count('visitor_hash'),
            'events' => $recent()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get()->map(fn (AnalyticsEvent $event): array => [
                'type' => $event->type,
                'path' => $event->path,
                'occurredAt' => $event->occurred_at,
                'source' => $event->utm_source ?: ($event->referrer_host ?: 'Direct / unknown'),
            ])->values()->all(),
        ];
    }
}

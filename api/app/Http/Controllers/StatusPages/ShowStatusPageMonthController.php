<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\MonthlyUptimeQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class ShowStatusPageMonthController
{
    /**
     * Show a status page's uptime for one month (from the month the page was created to this one), with its incidents
     * and the last 24 months to move between.
     *
     * @param  string  $slug
     * @param  string  $month  Y-m.
     * @param  MonthlyUptimeQuery  $uptime
     * @return JsonResponse
     */
    public function __invoke(string $slug, string $month, MonthlyUptimeQuery $uptime): JsonResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $first = CarbonImmutable::parse($page->created_at ?? now())->utc()->startOfMonth();
        abort_if($start === null || $start->greaterThan(CarbonImmutable::now('UTC')) || $start->lessThan($first), 404);
        $months = [];
        for ($cursor = CarbonImmutable::now('UTC')->startOfMonth(); $cursor->greaterThanOrEqualTo($first) && count($months) < 24; $cursor = $cursor->subMonth()) {
            $months[] = ['value' => $cursor->format('Y-m'), 'label' => $cursor->isoFormat('MMM YYYY')];
        }
        $report = $uptime->handle($page, $start);

        return response()->json([
            'page' => ['slug' => $page->slug, 'name' => $page->name, 'url' => $page->publicUrl()],
            'month' => $report['month'],
            'label' => $report['label'],
            'uptime' => $report['uptime'],
            'downtimeMinutes' => $report['downtime_minutes'],
            'components' => $report['components'],
            'incidents' => array_map(fn (array $incident): array => [
                'title' => $incident['title'],
                'component' => $incident['component'],
                'openedAt' => $incident['opened_at']->toIso8601String(),
                'resolvedAt' => $incident['resolved_at']?->toIso8601String(),
                'minutes' => $incident['minutes'],
            ], $report['incidents']),
            'months' => $months,
        ]);
    }
}

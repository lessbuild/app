<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;

/**
 * Weekly retention cohorts for sites whose snippet opts into recognising returning browsers: visitors grouped by the
 * week they were first seen, and how many came back in each following week.
 */
final class RetentionQuery
{
    /**
     * Build up to eight weekly cohorts (weeks start on Monday in the site's timezone) ending this week: each cohort's
     * size and the share of it active in weeks 1 to 7 after. Browsers first seen before the eight weeks aren't
     * counted in any cohort.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $weeks
     * @return array{cohorts: list<array{week: CarbonImmutable, size: int, returned: list<int|null>}>, tracked: bool}
     */
    public function handle(AnalyticsSite $site, int $weeks = 8): array
    {
        $firstWeek = CarbonImmutable::now($site->timezone)->startOfWeek()->subWeeks($weeks - 1);
        $firstSeen = AnalyticsEvent::query()->where('site_id', $site->id)->countable()->whereNotNull('returning_hash')
            ->groupBy('returning_hash')->toBase()->selectRaw('returning_hash, MIN(occurred_at) AS first_at')
            ->havingRaw('MIN(occurred_at) >= ?', [$firstWeek->utc()])
            ->pluck('first_at', 'returning_hash');
        $tracked = $firstSeen->isNotEmpty() || AnalyticsEvent::query()->where('site_id', $site->id)->whereNotNull('returning_hash')->exists();
        $week = fn (mixed $at): int => (int) floor(CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($site->timezone)->startOfWeek()->diffInWeeks($firstWeek->startOfWeek(), true));

        $members = array_fill(0, $weeks, []);
        foreach ($firstSeen as $hash => $at) {
            $members[min($weeks - 1, $week($at))][(string) $hash] = true;
        }
        $active = array_fill(0, $weeks, []);
        foreach (AnalyticsEvent::query()->where('site_id', $site->id)->countable()->whereNotNull('returning_hash')
            ->where('occurred_at', '>=', $firstWeek->utc())->toBase()->select(['returning_hash', 'occurred_at'])->cursor() as $row) {
            if ($firstSeen->has((string) $row->returning_hash)) {
                $active[min($weeks - 1, $week($row->occurred_at))][(string) $row->returning_hash] = true;
            }
        }

        $cohorts = [];
        $current = $weeks - 1;
        foreach ($members as $index => $cohort) {
            $size = count($cohort);
            $returned = [];
            for ($after = 1; $after <= 7; $after++) {
                $returned[] = $index + $after > $current || $size === 0 ? null
                    : (int) round(count(array_intersect_key($cohort, $active[$index + $after])) / $size * 100);
            }
            $cohorts[] = ['week' => $firstWeek->addWeeks($index), 'size' => $size, 'returned' => $returned];
        }

        return ['cohorts' => $cohorts, 'tracked' => $tracked];
    }
}

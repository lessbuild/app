<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsGoalConversion;
use Illuminate\Support\Facades\DB;

/** An A/B test's results: per variant, who saw it, how many converted, and whether the difference is real. */
final class ExperimentResultsQuery
{
    /**
     * Count, per variant, the visitors who saw it since the experiment started (or until it stopped) and how many of
     * them completed its goal, with each variant's lift over the control (the first variant) and a two-sided
     * p-value from a two-proportion z-test. Significant means p below 0.05.
     *
     * @param  AnalyticsExperiment  $experiment
     * @return list<array{variant: string, visitors: int, conversions: int, rate: float, lift: float|null, p_value: float|null, significant: bool}>
     */
    public function handle(AnalyticsExperiment $experiment): array
    {
        $until = $experiment->stopped_at ?? now();
        $pgsql = DB::getDriverName() === 'pgsql';
        $name = $pgsql ? "properties->>'experiment'" : "json_extract(properties, '$.experiment')";
        $variant = $pgsql ? "properties->>'variant'" : "json_extract(properties, '$.variant')";
        $exposures = DB::table('analytics_events')->where('site_id', $experiment->site_id)->where('type', 'experiment')
            ->whereBetween('occurred_at', [$experiment->started_at, $until])->whereRaw("{$name} = ?", [$experiment->key])
            ->whereNotNull('visitor_hash')->selectRaw("DISTINCT visitor_hash, {$variant} AS variant");
        $converters = $experiment->goal_id === null ? DB::query()->selectRaw('NULL AS visitor_hash')->whereRaw('1 = 0')
            : AnalyticsGoalConversion::query()->toBase()->join('analytics_events as e', 'e.id', '=', 'analytics_goal_conversions.analytics_event_id')
                ->where('analytics_goal_conversions.goal_id', $experiment->goal_id)->whereBetween('analytics_goal_conversions.converted_at', [$experiment->started_at, $until])
                ->whereNotNull('e.visitor_hash')->selectRaw('DISTINCT e.visitor_hash AS visitor_hash');
        $counts = DB::query()->fromSub($exposures, 'x')->leftJoinSub($converters, 'c', 'c.visitor_hash', '=', 'x.visitor_hash')
            ->selectRaw('x.variant AS variant, COUNT(*) AS visitors, COUNT(c.visitor_hash) AS conversions')->groupBy('x.variant')->get()
            ->keyBy(fn (object $row): string => (string) $row->variant);

        $results = [];
        $control = null;
        foreach ($experiment->variants as $index => $name) {
            $row = $counts->get($name);
            $visitors = (int) ($row->visitors ?? 0);
            $conversions = (int) ($row->conversions ?? 0);
            $rate = $visitors > 0 ? $conversions / $visitors : 0.0;
            if ($index === 0) {
                $control = ['visitors' => $visitors, 'conversions' => $conversions, 'rate' => $rate];
            }
            $pValue = $index === 0 || $control === null ? null : $this->pValue($control['conversions'], $control['visitors'], $conversions, $visitors);
            $results[] = [
                'variant' => $name,
                'visitors' => $visitors,
                'conversions' => $conversions,
                'rate' => round($rate * 100, 2),
                'lift' => $index === 0 || $control === null || $control['rate'] === 0.0 ? null : round(($rate - $control['rate']) / $control['rate'] * 100, 1),
                'p_value' => $pValue === null ? null : round($pValue, 4),
                'significant' => $pValue !== null && $pValue < 0.05,
            ];
        }

        return $results;
    }

    /**
     * Get the two-sided p-value for the difference between two conversion rates (a two-proportion z-test), or null
     * when there's too little data.
     *
     * @param  int  $conversionsA
     * @param  int  $visitorsA
     * @param  int  $conversionsB
     * @param  int  $visitorsB
     * @return float|null
     */
    private function pValue(int $conversionsA, int $visitorsA, int $conversionsB, int $visitorsB): ?float
    {
        if ($visitorsA < 1 || $visitorsB < 1) {
            return null;
        }
        $pooled = ($conversionsA + $conversionsB) / ($visitorsA + $visitorsB);
        $error = sqrt($pooled * (1 - $pooled) * (1 / $visitorsA + 1 / $visitorsB));
        if ($error === 0.0) {
            return null;
        }
        $z = abs($conversionsB / $visitorsB - $conversionsA / $visitorsA) / $error;

        return $this->erfc($z / M_SQRT2);
    }

    /**
     * Approximate the complementary error function (Numerical Recipes' erfcc, accurate to about 1e-7).
     *
     * @param  float  $x
     * @return float
     */
    private function erfc(float $x): float
    {
        $t = 1 / (1 + 0.5 * abs($x));
        $value = $t * exp(-$x * $x - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807
            + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));

        return $x >= 0 ? $value : 2 - $value;
    }
}

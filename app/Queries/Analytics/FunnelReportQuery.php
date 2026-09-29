<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsFunnel;
use Carbon\CarbonImmutable;

/**
 * Counts how far visitors got through a funnel: each step counts visitors who reached it after all the earlier
 * steps, in order, within the period. A visitor is their session, or their visitor ID when there's no session;
 * anonymous events can't be followed past the first step.
 */
final class FunnelReportQuery
{
    /**
     * Report the funnel over the last days: each step with how many reached it, its share of the first step, and the
     * share of the step before that carried on.
     *
     * @param  AnalyticsFunnel  $funnel
     * @param  int  $days
     * @return list<array{label: string, visitors: int, of_start: float, of_previous: float|null}>
     */
    public function handle(AnalyticsFunnel $funnel, int $days = 30): array
    {
        $steps = $funnel->steps;
        $reached = array_fill(0, count($steps), 0);
        $identity = null;
        $progress = 0;
        $events = AnalyticsEvent::query()->where('site_id', $funnel->site_id)->whereIn('type', ['pageview', 'event'])
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays($days))
            ->select(['id', 'type', 'path', 'properties', 'visitor_hash', 'session_id', 'occurred_at'])
            ->orderByRaw('COALESCE(session_id, visitor_hash)')->orderBy('occurred_at')->orderBy('id');
        foreach ($events->lazy(2000) as $event) {
            $who = $event->session_id ?? $event->visitor_hash ?? 'anonymous-'.$event->id;
            if ($who !== $identity) {
                $identity = $who;
                $progress = 0;
            }
            if ($progress < count($steps) && $this->matches($steps[$progress], $event)) {
                $reached[$progress]++;
                $progress++;
            }
        }

        $report = [];
        foreach ($steps as $index => $step) {
            $report[] = [
                'label' => AnalyticsFunnel::stepLabel($step), 'visitors' => $reached[$index],
                'of_start' => $reached[0] > 0 ? round($reached[$index] / $reached[0] * 100, 1) : 0.0,
                'of_previous' => $index === 0 ? null : ($reached[$index - 1] > 0 ? round($reached[$index] / $reached[$index - 1] * 100, 1) : 0.0),
            ];
        }

        return $report;
    }

    /**
     * Determine whether an event completes a step: a pageview on the path (exactly, or starting with it), or a custom
     * event with that name.
     *
     * @param  array{kind: string, match: string, value: string}  $step
     * @param  AnalyticsEvent  $event
     * @return bool
     */
    private function matches(array $step, AnalyticsEvent $event): bool
    {
        if ($step['kind'] === 'event') {
            return $event->type === 'event' && data_get($event->properties, 'name') === $step['value'];
        }

        return $event->type === 'pageview' && ($step['match'] === 'prefix' ? str_starts_with($event->path, $step['value']) : $event->path === $step['value']);
    }
}

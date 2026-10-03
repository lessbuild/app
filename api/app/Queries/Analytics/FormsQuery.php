<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;

/** Form analytics: how many people start each form, how many send it, and which field they leave on. */
final class FormsQuery
{
    /**
     * Follow each visitor through each form in the period (up to 50,000 form events): who started it (focused a field),
     * who sent it, how many reached each field, and the last field focused by those who left without sending.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @return list<array{form: string, started: int, submitted: int, fields: list<array{field: string, reached: int, left: int}>}>
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period): array
    {
        /** @var array<string, array<string, array{fields: array<string, true>, submitted: bool}>> $journeys */
        $journeys = [];
        $events = AnalyticsEvent::query()->where('site_id', $site->id)->countable()->where('type', 'form')
            ->whereBetween('occurred_at', [$period->start->utc(), $period->end->utc()])
            ->orderBy('occurred_at')->orderBy('id')->limit(50000)->get(['properties', 'session_id', 'visitor_hash', 'event_id']);
        foreach ($events as $event) {
            $form = (string) ($event->properties['form'] ?? '');
            if ($form === '') {
                continue;
            }
            $who = (string) ($event->session_id ?? $event->visitor_hash ?? $event->event_id);
            $journey = $journeys[$form][$who] ?? ['fields' => [], 'submitted' => false];
            if (($event->properties['action'] ?? null) === 'submit') {
                $journey['submitted'] = true;
            } elseif (isset($event->properties['field'])) {
                $journey['fields'][(string) $event->properties['field']] = true;
            }
            $journeys[$form][$who] = $journey;
        }

        $forms = [];
        foreach ($journeys as $form => $visitors) {
            /** @var array<string, array{field: string, reached: int, left: int}> $fields */
            $fields = [];
            $started = 0;
            $submitted = 0;
            foreach ($visitors as $journey) {
                if ($journey['fields'] === [] && ! $journey['submitted']) {
                    continue;
                }
                $started++;
                $submitted += $journey['submitted'] ? 1 : 0;
                foreach (array_keys($journey['fields']) as $field) {
                    $row = $fields[(string) $field] ?? ['field' => (string) $field, 'reached' => 0, 'left' => 0];
                    $row['reached']++;
                    $fields[(string) $field] = $row;
                }
                $last = array_key_last($journey['fields']);
                if (! $journey['submitted'] && $last !== null && isset($fields[(string) $last])) {
                    $fields[(string) $last]['left']++;
                }
            }
            $forms[] = ['form' => (string) $form, 'started' => $started, 'submitted' => $submitted, 'fields' => array_values($fields)];
        }
        usort($forms, fn (array $a, array $b): int => $b['started'] <=> $a['started']);

        return array_slice($forms, 0, 20);
    }
}

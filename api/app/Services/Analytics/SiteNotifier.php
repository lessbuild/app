<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Notifications\AnalyticsSiteReportNotification;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends sites' scheduled reports and CSV exports (weekly on Mondays, monthly on the 1st, from 8am in the site's
 * timezone), traffic spike alerts and unusual traffic alerts, by email or Slack. Each period, spike and day goes once;
 * failures are recorded on the row.
 */
final class SiteNotifier
{
    /**
     * Create a new SiteNotifier instance.
     *
     * @param  AnalyticsReportQuery  $reports  Builds the report for the period.
     * @param  LiveVisitorsQuery  $live  Counts current visitors.
     * @param  ReportCsv  $csv  Writes a report as CSV.
     */
    public function __construct(private readonly AnalyticsReportQuery $reports, private readonly LiveVisitorsQuery $live, private readonly ReportCsv $csv) {}

    /**
     * Send the reports and CSV exports that are due: last week's on Monday from 8am, last month's on the 1st from 8am,
     * in each site's timezone (or up to a day or two later if sending was down). Returns how many were sent.
     *
     * @return int
     */
    public function sendDueReports(): int
    {
        $sent = 0;
        AnalyticsNotification::query()->whereIn('kind', array_keys(AnalyticsNotification::SCHEDULED))->with('site.project')->orderBy('id')
            ->each(function (AnalyticsNotification $notification) use (&$sent): void {
                $site = $notification->site;
                $kind = AnalyticsNotification::SCHEDULED[$notification->kind];
                $now = CarbonImmutable::now($site->timezone);
                if ($now->hour < 8) {
                    return;
                }
                // Sent early in the period (so a new report waits for the next one); a day or two of grace covers downtime.
                if ($kind === 'weekly' ? $now->dayOfWeekIso > 2 : $now->day > 3) {
                    return;
                }
                if ($kind === 'weekly') {
                    $from = $now->startOfWeek()->subWeek();
                    [$period, $until] = [$from->format('o-\WW'), $from->endOfWeek()];
                } else {
                    $from = $now->startOfMonth()->subMonthNoOverflow();
                    [$period, $until] = [$from->format('Y-m'), $from->endOfMonth()];
                }
                if ($notification->last_period === $period) {
                    return;
                }
                $range = ReportPeriod::between($site->timezone, $from->toDateString(), $until->toDateString());
                if ($range === null) {
                    return;
                }
                $this->deliver($notification, $notification->kind === $kind ? $this->report($site, $range, $kind) : $this->export($notification, $range, $kind), $period);
                $sent++;
            });

        return $sent;
    }

    /**
     * Send spike alerts for sites with at least their threshold of visitors right now, at most once every three hours
     * each. Returns how many were sent.
     *
     * @return int
     */
    public function checkSpikes(): int
    {
        $sent = 0;
        AnalyticsNotification::query()->where('kind', 'spike')->with('site.project')->orderBy('id')
            ->each(function (AnalyticsNotification $notification) use (&$sent): void {
                if ($notification->last_sent_at !== null && $notification->last_sent_at->gt(now()->subHours(AnalyticsNotification::SPIKE_COOLDOWN_HOURS))) {
                    return;
                }
                $site = $notification->site;
                $live = $this->live->handle($site);
                if ($live['visitorCount'] < (int) $notification->threshold) {
                    return;
                }
                $pages = array_count_values(array_map(fn (array $event): string => $event['path'], $live['events']));
                arsort($pages);
                $sources = array_count_values(array_map(fn (array $event): string => $event['source'], $live['events']));
                arsort($sources);
                $this->deliver($notification, [
                    'subject' => (string) __('Traffic spike on :site: :count visitors now', ['site' => $site->name, 'count' => number_format($live['visitorCount'])]),
                    'lines' => array_values(array_filter([
                        (string) __(':count people are on :site right now (your alert is set at :threshold).', ['count' => number_format($live['visitorCount']), 'site' => $site->name, 'threshold' => number_format((int) $notification->threshold)]),
                        $pages === [] ? null : (string) __('Busiest pages: :pages', ['pages' => implode(', ', array_slice(array_keys($pages), 0, 3))]),
                        $sources === [] ? null : (string) __('Coming from: :sources', ['sources' => implode(', ', array_slice(array_keys($sources), 0, 3))]),
                    ])),
                    'url' => route('analytics.overview', [$site->project_id, 'site' => $site->id, 'days' => 1]),
                ], now()->toDateString());
                $sent++;
            });

        return $sent;
    }

    /**
     * Send unusual traffic alerts, once a day from 8am in each site's timezone: when yesterday's visitors or
     * conversions were far from what the same weekday usually brings (more than three standard deviations and 30%
     * away from the average of the last eight such days, with at least four of them to go on and a big enough
     * average to judge). Returns how many were sent.
     *
     * @return int
     */
    public function checkAnomalies(): int
    {
        $sent = 0;
        AnalyticsNotification::query()->where('kind', 'anomaly')->with('site.project')->orderBy('id')
            ->each(function (AnalyticsNotification $notification) use (&$sent): void {
                $site = $notification->site;
                $now = CarbonImmutable::now($site->timezone);
                $day = $now->subDay()->toDateString();
                if ($now->hour < 8 || $notification->last_period === $day) {
                    return;
                }
                $history = array_map(fn (int $weeks): string => $now->subDay()->subWeeks($weeks)->toDateString(), range(1, 8));
                $totals = AnalyticsDailyAggregate::query()->where('site_id', $site->id)->where('dimension', 'all')
                    ->whereBetween('local_date', [end($history), $day.' 23:59:59'])->get(['local_date', 'visitors', 'conversions'])->toBase()
                    ->keyBy(fn (AnalyticsDailyAggregate $row): string => $row->local_date->toDateString())->only([$day, ...$history]);
                $lines = [];
                foreach (['visitors' => 20, 'conversions' => 5] as $measure => $minimum) {
                    $past = array_values(array_map(fn (string $date): int => (int) ($totals->get($date)->{$measure} ?? 0), array_filter($history, fn (string $date): bool => $totals->has($date))));
                    $line = $this->unusual($measure, (int) ($totals->get($day)->{$measure} ?? 0), $past, $minimum, $now->subDay()->isoFormat('dddd'));
                    if ($line !== null) {
                        $lines[] = $line;
                    }
                }
                if ($lines === []) {
                    // Nothing unusual: remember the day was checked so it isn't looked at again.
                    $notification->forceFill(['last_period' => $day])->save();

                    return;
                }
                $this->deliver($notification, [
                    'subject' => (string) __('Unusual traffic on :site yesterday', ['site' => $site->name]),
                    'lines' => $lines,
                    'url' => route('analytics.overview', [$site->project_id, 'site' => $site->id, 'days' => 30]),
                ], $day);
                $sent++;
            });

        return $sent;
    }

    /**
     * Describe a day's number that's far from normal for that weekday, or null when it's within the usual range or
     * there's too little history to judge.
     *
     * @param  string  $measure  visitors or conversions
     * @param  int  $value  the day's number
     * @param  list<int>  $past  the same weekday's numbers in earlier weeks
     * @param  int  $minimum  the smallest average worth judging
     * @param  string  $weekday  the day's name, for the message
     * @return string|null
     */
    private function unusual(string $measure, int $value, array $past, int $minimum, string $weekday): ?string
    {
        if (count($past) < 4) {
            return null;
        }
        $mean = array_sum($past) / count($past);
        if ($mean < $minimum) {
            return null;
        }
        $deviation = sqrt(array_sum(array_map(fn (int $n): float => ($n - $mean) ** 2, $past)) / count($past));
        $difference = $value - $mean;
        if (abs($difference) <= max(3 * $deviation, 0.3 * $mean)) {
            return null;
        }
        $percent = (int) round(abs($difference) / $mean * 100);
        $label = $measure === 'visitors' ? __('Visitors') : __('Conversions');

        return (string) ($difference < 0
            ? __(':measure were :percent% below normal: :value, against about :usual on a typical :weekday.', ['measure' => $label, 'percent' => $percent, 'value' => number_format($value), 'usual' => number_format((int) round($mean)), 'weekday' => $weekday])
            : __(':measure were :percent% above normal: :value, against about :usual on a typical :weekday.', ['measure' => $label, 'percent' => $percent, 'value' => number_format($value), 'usual' => number_format((int) round($mean)), 'weekday' => $weekday]));
    }

    /**
     * Build a scheduled CSV export's message: the report for the period, with the saved view's filters, attached.
     *
     * @param  AnalyticsNotification  $notification
     * @param  ReportPeriod  $period
     * @param  string  $kind  weekly or monthly
     * @return array{subject: string, lines: list<string>, url: string, attachment: array{name: string, csv: string}}
     */
    private function export(AnalyticsNotification $notification, ReportPeriod $period, string $kind): array
    {
        $site = $notification->site;
        $filters = $notification->filters ?? [];
        $dates = $period->start->toDateString().'-to-'.$period->end->toDateString();

        return [
            'subject' => $kind === 'weekly'
                ? (string) __(':site: last week’s CSV (:view)', ['site' => $site->name, 'view' => $notification->view_name ?? __('all traffic')])
                : (string) __(':site: :month’s CSV (:view)', ['site' => $site->name, 'month' => $period->start->isoFormat('MMMM YYYY'), 'view' => $notification->view_name ?? __('all traffic')]),
            'lines' => [(string) __('The report for :from to :to is attached as a CSV.', ['from' => $period->start->toFormattedDateString(), 'to' => $period->end->toFormattedDateString()])],
            'url' => route('analytics.overview', [$site->project_id, 'site' => $site->id, ...$period->query(), ...$filters]),
            'attachment' => ['name' => Str::slug($site->name.' '.($notification->view_name ?? '')).'-'.$dates.'.csv', 'csv' => $this->csv->render($site, $period, $filters)],
        ];
    }

    /**
     * Build a report's message: the headline numbers against the period before, top pages and sources.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  string  $kind  weekly or monthly
     * @return array{subject: string, lines: list<string>, url: string}
     */
    private function report(AnalyticsSite $site, ReportPeriod $period, string $kind): array
    {
        $report = $this->reports->handle($site, $period);
        $metrics = [];
        foreach ($report['metrics'] as $metric) {
            $metrics[] = __($metric['label']).': '.$metric['value'].($metric['change'] !== null && $metric['change'] !== 'New' ? ' ('.$metric['change'].')' : '');
        }
        $top = fn (array $rows): string => implode(', ', array_map(fn (array $row): string => $row['label'].' ('.number_format($row['value']).')', array_slice($rows, 0, 5)));
        $dates = $period->start->isoFormat('D MMM').' – '.$period->end->isoFormat('D MMM YYYY');

        return [
            'subject' => $kind === 'weekly' ? (string) __(':site last week (:dates)', ['site' => $site->name, 'dates' => $dates]) : (string) __(':site in :month', ['site' => $site->name, 'month' => $period->start->isoFormat('MMMM YYYY')]),
            'lines' => array_values(array_filter([
                implode(' · ', $metrics),
                $report['pages'] === [] ? null : (string) __('Top pages: :pages', ['pages' => $top($report['pages'])]),
                $report['sources'] === [] ? null : (string) __('Top sources: :sources', ['sources' => $top($report['sources'])]),
                ($report['goals'] ?? []) === [] ? null : (string) __('Goals: :goals', ['goals' => implode(', ', array_map(fn (array $goal): string => $goal['name'].' ('.number_format($goal['value']).')', $report['goals']))]),
            ])),
            'url' => route('analytics.overview', [$site->project_id, 'site' => $site->id, ...$period->query()]),
        ];
    }

    /**
     * Send a message by the notification's channel and record the result.
     *
     * @param  AnalyticsNotification  $notification
     * @param  array{subject: string, lines: list<string>, url: string, attachment?: array{name: string, csv: string}}  $message
     * @param  string  $period  what was sent, so it isn't sent again
     * @return void
     */
    private function deliver(AnalyticsNotification $notification, array $message, string $period): void
    {
        $error = null;
        try {
            if ($notification->channel === 'slack') {
                Http::timeout(10)->withoutRedirecting()->post($notification->target, [
                    'text' => '*'.$message['subject']."*\n".implode("\n", $message['lines'])."\n<".$message['url'].'|'.__('Open the report').'>',
                    'unfurl_links' => false,
                ])->throw();
            } else {
                Notification::route('mail', $notification->target)->notify(new AnalyticsSiteReportNotification($message['subject'], $message['lines'], $message['url'], $message['attachment'] ?? null));
            }
        } catch (Throwable $exception) {
            $error = str($exception->getMessage())->limit(500)->toString();
        }
        $notification->forceFill(['last_period' => $period, 'last_sent_at' => now(), 'last_error' => $error])->save();
    }
}

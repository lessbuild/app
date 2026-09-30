<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Notifications\AnalyticsSiteReportNotification;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends sites' scheduled reports (weekly on Mondays, monthly on the 1st, from 8am in the site's timezone) and traffic
 * spike alerts, by email or Slack. Each report period and each spike goes once; failures are recorded on the row.
 */
final class SiteNotifier
{
    /**
     * Create a new SiteNotifier instance.
     *
     * @param  AnalyticsReportQuery  $reports  Builds the report for the period.
     * @param  LiveVisitorsQuery  $live  Counts current visitors.
     */
    public function __construct(private readonly AnalyticsReportQuery $reports, private readonly LiveVisitorsQuery $live) {}

    /**
     * Send the reports that are due: last week's on Monday from 8am, last month's on the 1st from 8am, in each site's
     * timezone (or up to a day or two later if sending was down). Returns how many were sent.
     *
     * @return int
     */
    public function sendDueReports(): int
    {
        $sent = 0;
        AnalyticsNotification::query()->whereIn('kind', ['weekly', 'monthly'])->with('site.project')->orderBy('id')
            ->each(function (AnalyticsNotification $notification) use (&$sent): void {
                $site = $notification->site;
                $now = CarbonImmutable::now($site->timezone);
                if ($now->hour < 8) {
                    return;
                }
                // Sent early in the period (so a new report waits for the next one); a day or two of grace covers downtime.
                if ($notification->kind === 'weekly' ? $now->dayOfWeekIso > 2 : $now->day > 3) {
                    return;
                }
                if ($notification->kind === 'weekly') {
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
                $this->deliver($notification, $this->report($site, $range, $notification->kind), $period);
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
     * @param  array{subject: string, lines: list<string>, url: string}  $message
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
                Notification::route('mail', $notification->target)->notify(new AnalyticsSiteReportNotification($message['subject'], $message['lines'], $message['url']));
            }
        } catch (Throwable $exception) {
            $error = str($exception->getMessage())->limit(500)->toString();
        }
        $notification->forceFill(['last_period' => $period, 'last_sent_at' => now(), 'last_error' => $error])->save();
    }
}

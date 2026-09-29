<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyReportDelivery;
use App\Notifications\WeeklyReportNotification;
use App\Services\Monitoring\EmailDeliveryLedger;
use Carbon\CarbonImmutable;

/**
 * The Monday email: for each of an account's projects, last week's deploys, incidents, uptime and visits, with the
 * week before for comparison.
 *
 * @phpstan-type ProjectWeek array{name: string, url: string, deploys: int, deploys_failed: int, incidents: int, incidents_open: int, uptime: ?float, visits: ?int, visits_before: ?int}
 * @phpstan-type Report array{account: string, from: CarbonImmutable, until: CarbonImmutable, projects: list<ProjectWeek>}
 */
final class WeeklyReport
{
    /**
     * Create a new WeeklyReport instance.
     *
     * @param  EmailDeliveryLedger  $ledger  Makes sure each week's report is sent to a person once and records how it went.
     */
    public function __construct(private readonly EmailDeliveryLedger $ledger) {}

    /**
     * Send the report for the week ending at `$until` to every member who wants it, skipping accounts where nothing
     * happened. Returns how many were sent, skipped (already sent) and failed.
     *
     * @param  CarbonImmutable  $until  the end of the week, exclusive
     * @param  string|null  $accountId  only this account
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function send(CarbonImmutable $until, ?string $accountId = null): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $from = $until->subWeek();
        $accounts = Account::query()->when($accountId !== null, fn ($query) => $query->whereKey($accountId))->with('memberships.user');
        foreach ($accounts->lazyById(100) as $account) {
            $recipients = $account->memberships->map->user
                ->filter(fn (User $user): bool => $user->weekly_report_emails && $user->email_verified_at !== null)->values();
            if ($recipients->isEmpty()) {
                continue;
            }
            $report = $this->report($account, $from, $until);
            if ($report['projects'] === []) {
                continue;
            }
            foreach ($recipients as $recipient) {
                $totals[$this->ledger->send(WeeklyReportDelivery::class, [
                    'account_id' => $account->id, 'recipient_id' => $recipient->id, 'period_start' => $from->format('Y-m-d H:i:s.u'),
                ], ['project_count' => count($report['projects'])],
                    fn () => $recipient->notifyNow(new WeeklyReportNotification($report)))]++;
            }
        }

        return $totals;
    }

    /**
     * Build an account's report for a week. Projects where nothing was measured (no deploys, incidents, checks or
     * visits) are left out.
     *
     * @param  Account  $account
     * @param  CarbonImmutable  $from  inclusive
     * @param  CarbonImmutable  $until  exclusive
     * @return Report
     */
    public function report(Account $account, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $projects = [];
        foreach (Project::query()->whereBelongsTo($account)->orderBy('name')->get() as $project) {
            $week = $this->projectWeek($project, $from, $until);
            if ($week['deploys'] + $week['incidents'] + $week['incidents_open'] > 0 || $week['uptime'] !== null || ($week['visits'] ?? 0) > 0) {
                $projects[] = $week;
            }
        }

        return ['account' => $account->name, 'from' => $from, 'until' => $until, 'projects' => $projects];
    }

    /**
     * Measure one project's week.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return ProjectWeek
     */
    private function projectWeek(Project $project, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $builds = Build::query()->whereHas('repository', fn ($query) => $query->where('project_id', $project->id))
            ->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $incidents = Incident::query()->where('project_id', $project->id);
        /** @var list<int> $sites */
        $sites = AnalyticsSite::query()->where('project_id', $project->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return [
            'name' => $project->name,
            'url' => route('projects.show', $project),
            'deploys' => (clone $builds)->whereIn('status', [Build::STATUS_SUCCEEDED, Build::STATUS_FAILED])->count(),
            'deploys_failed' => (clone $builds)->where('status', Build::STATUS_FAILED)->count(),
            'incidents' => (clone $incidents)->where('opened_at', '>=', $from)->where('opened_at', '<', $until)->count(),
            'incidents_open' => (clone $incidents)->whereNull('resolved_at')->count(),
            'uptime' => $this->uptime($project, $from, $until),
            'visits' => $sites === [] ? null : $this->visits($sites, $from, $until),
            'visits_before' => $sites === [] ? null : $this->visits($sites, $from->subWeek(), $from),
        ];
    }

    /**
     * Get the share of the project's completed checks that passed during the week, as a percentage, or null when
     * nothing was checked.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return float|null
     */
    private function uptime(Project $project, CarbonImmutable $from, CarbonImmutable $until): ?float
    {
        $monitors = Monitor::query()->whereIn('environment_id', $project->environments()->select('id'))->pluck('id');
        if ($monitors->isEmpty()) {
            return null;
        }
        $counts = MonitorCheck::query()->whereIn('monitor_id', $monitors)->where('status', 'completed')
            ->whereIn('outcome', ['up', 'down'])->where('scheduled_at', '>=', $from)->where('scheduled_at', '<', $until)
            ->toBase()->selectRaw('outcome, count(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $up = (int) ($counts['up'] ?? 0);
        $measured = $up + (int) ($counts['down'] ?? 0);

        return $measured === 0 ? null : round($up / $measured * 100, 2);
    }

    /**
     * Count the sites' visits on the local days from `$from` up to `$until`.
     *
     * @param  list<int>  $siteIds
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return int
     */
    private function visits(array $siteIds, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return (int) AnalyticsDailyAggregate::query()->whereIn('site_id', $siteIds)->where('dimension', 'all')
            ->where('local_date', '>=', $from->toDateString())->where('local_date', '<', $until->toDateString())
            ->sum('visits');
    }
}
